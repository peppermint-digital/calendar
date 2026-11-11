<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Peppermint\Calendar\Http\CalendarRoutes;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Sources\RemoteEventSource;
use Peppermint\Calendar\Sources\RemoteResult;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;
use Peppermint\Calendar\Tests\Fixtures\FakeRemoteCalendar;
use Peppermint\Calendar\Tests\Fixtures\WritableDemoSource;

/**
 * Die vier Vorgaenge am Kalender eines anderen Systems (#7489).
 *
 * Geprueft wird die Verdrahtung, nicht die Quelle — die steht in
 * `WritableSourceTest`. Hier: dass die Routen gelegt werden koennen, dass eine
 * nur lesbare Quelle 404 gibt, dass ein Fehlschlag drueben 502 wird, und dass
 * `all_day` nur mitreist, wenn es angegeben war.
 */
beforeEach(function () {
    DemoSource::$available = true;
    DemoSource::$throws = false;
    WritableDemoSource::$writable = true;
    WritableDemoSource::$received = [];
    WritableDemoSource::$verschoben = [];
    WritableDemoSource::$geloescht = [];
    WritableDemoSource::$scheitert = false;

    config()->set('calendar.sources', [DemoSource::class, WritableDemoSource::class]);
    app()->forgetInstance(EventSourceRegistry::class);

    // Die `web`-Gruppe verschluesselt Sitzung und Cookies und verlangt
    // deshalb einen Schluessel. Er steht hier und nicht in der TestCase, weil
    // nur dieser Test eine echte Mittelschicht-Gruppe braucht.
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

    // So, wie ein Produkt sie legt: mit SEINEM Praefix, hinter SEINER
    // Schranke. Das Paket meldet nichts von selbst an.
    Route::middleware('web')->prefix('api/calendar')->group(fn () => CalendarRoutes::sources());

    $this->person = new GenericUser(['id' => 7]);
});

it('nennt die Terminarten des Zielsystems', function () {
    $antwort = $this->actingAs($this->person)->getJson('api/calendar/sources/writable-demo/kinds');

    $antwort->assertOk()
        ->assertJsonPath('kinds.0.key', 'business')
        ->assertJsonPath('kinds.0.requires', ['location']);
});

it('schickt mit, ob eine fremde Art von Hand anlegbar ist', function () {
    // Die Gegenprobe zur Lesestelle, und sie ist der Zweck des Tests: Die
    // Artenliste der Maske siebt mit `creatable` (#9796). Kommt der Schluessel
    // hier nicht an, liest sie einen leeren Wert, der wie ein gueltiger
    // aussieht — nichts wird rot, und eine Art, die beim Speichern abgelehnt
    // wird, steht trotzdem zur Wahl (Wiki #595).
    $antwort = $this->actingAs($this->person)->getJson('api/calendar/sources/writable-demo/kinds');

    $antwort->assertOk()->assertJsonPath('kinds.0.creatable', true);

    // Und zwar an JEDER Art, nicht nur an der ersten.
    foreach ($antwort->json('kinds') as $art) {
        expect($art)->toHaveKey('creatable');
    }
});

it('legt drueben an und zeigt die Fassung des Zielsystems', function () {
    // Das andere System rundet auf die volle Stunde. Angezeigt werden muss
    // SEINE Antwort — sonst steht im Kalender etwas anderes als drueben.
    $antwort = $this->actingAs($this->person)->postJson('api/calendar/sources/writable-demo/events', [
        'kind' => 'business',
        'title' => 'Kundentermin',
        'starts_at' => '2026-10-13T09:20:00+02:00',
        'ends_at' => '2026-10-13T10:40:00+02:00',
        'location' => 'Berlin',
    ]);

    $antwort->assertCreated()
        ->assertJsonPath('event.id', 'writable-demo:99')
        ->assertJsonPath('event.title', 'Kundentermin');

    expect(WritableDemoSource::$received)->toHaveCount(1)
        ->and(WritableDemoSource::$received[0]->location)->toBe('Berlin');
});

it('verlangt die Felder, ohne die drueben nichts entstehen kann', function () {
    $this->actingAs($this->person)
        ->postJson('api/calendar/sources/writable-demo/events', ['title' => 'Ohne Art und Zeit'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['kind', 'starts_at', 'ends_at']);
});

it('laesst ein Ende vor dem Anfang nicht durch', function () {
    $this->actingAs($this->person)
        ->postJson('api/calendar/sources/writable-demo/events', [
            'kind' => 'business',
            'title' => 'Rueckwaerts',
            'starts_at' => '2026-10-13T10:00:00+02:00',
            'ends_at' => '2026-10-13T09:00:00+02:00',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_at']);
});

it('schickt all_day nur mit, wenn es angegeben war', function () {
    // Ein ausdrueckliches `false` ist eine Angabe. Es zu verlieren machte beim
    // Verschieben aus jedem Ganztagstermin einen Zeittermin.
    $this->actingAs($this->person)->patchJson('api/calendar/sources/writable-demo/events/4711', [
        'starts_at' => '2026-10-13T09:00:00+02:00',
        'ends_at' => '2026-10-13T10:00:00+02:00',
    ]);

    expect(WritableDemoSource::$verschoben[0]['allDay'])->toBeNull();

    $this->actingAs($this->person)->patchJson('api/calendar/sources/writable-demo/events/4711', [
        'starts_at' => '2026-10-13T09:00:00+02:00',
        'ends_at' => '2026-10-13T10:00:00+02:00',
        'all_day' => false,
    ]);

    expect(WritableDemoSource::$verschoben[1]['allDay'])->toBeFalse();
});

it('traegt eine Kennung, die nicht nur aus Ziffern besteht', function () {
    // Fremde Kennungen sind kein Zahlenraum: Eintraege aus CalDAV-Kalendern
    // enden auf `.ics`. Eine Route, die nur Ziffern annimmt, haette sie
    // lautlos auf 404 geschickt.
    $this->actingAs($this->person)
        ->deleteJson('api/calendar/sources/writable-demo/events/7e3f-aa12.ics')
        ->assertOk();

    expect(WritableDemoSource::$geloescht)->toBe(['7e3f-aa12.ics']);
});

it('gibt 404 fuer eine Quelle, die man nur ansehen darf', function () {
    // Kein Fehler, sondern die Antwort auf „nicht freigeschaltet": Eine
    // lesende Quelle hat die Schreibverfahren gar nicht.
    $this->actingAs($this->person)
        ->getJson('api/calendar/sources/demo/kinds')
        ->assertNotFound();
});

it('gibt 404, sobald niemand mehr dort schreiben darf', function () {
    // Verliert das Zielsystem das Werkzeug oder die Freigabe, verschwindet es
    // von selbst — und ein Aufruf, der davor noch moeglich schien, findet
    // nichts mehr.
    WritableDemoSource::$writable = false;
    app()->forgetInstance(EventSourceRegistry::class);

    $this->actingAs($this->person)
        ->getJson('api/calendar/sources/writable-demo/kinds')
        ->assertNotFound();
});

/**
 * Der ganze Weg bei einem unerreichbaren Zielsystem (Bug #1416).
 *
 * Absichtlich mit der ECHTEN {@see RemoteEventSource} und nicht mit der
 * Vorrichtung: Geprueft werden soll die Kette, nicht die Zusage einer
 * Attrappe — fremdes System antwortet nicht, Quelle wirft, Controller
 * antwortet 502. Dreht man das alte `return []` in `kinds()` zurueck, faellt
 * dieser Test mit 200 statt 502.
 */
it('macht aus einem unerreichbaren Zielsystem beim Artenabruf 502', function () {
    // Dieser Abruf war der einzige der vier, der NICHT im 502-Weg lag: Ein
    // unerreichbares Zielsystem beantwortete ihn mit 200 und einer leeren
    // Artenliste. Die Maske konnte „nicht erreichbar" deshalb nie sagen — der
    // Hinweis am KindPicker war unerreichbarer Code.
    $fremd = new FakeRemoteCalendar;
    $fremd->antworten['calendar.kinds.list'] = RemoteResult::failed('Zeitueberschreitung nach 5s', 'unreachable');

    app(EventSourceRegistry::class)->register(new RemoteEventSource($fremd));

    $this->actingAs($this->person)
        ->getJson('api/calendar/sources/verwaltung/kinds')
        ->assertStatus(502)
        // Mit Quelle und Vorgang, damit die Oberflaeche sagen kann, WELCHES
        // System bei WELCHEM Schritt nicht mitgespielt hat.
        ->assertJsonPath('source', 'verwaltung')
        ->assertJsonPath('operation', 'Terminarten holen');
});

/**
 * Und die Gegenprobe zu der Annahme, an der Bug #1416 haengen geblieben war:
 * Ein unerreichbares System faellt NICHT vorher aus der Liste der
 * beschreibbaren Ziele heraus.
 *
 * Waere es so — die Vermutung im Bugtext —, waere die Antwort oben ein 404
 * und kein 502. `isWritable()` fragt eine lokale Faehigkeitsliste und ein
 * lokales Recht; es kennt die Erreichbarkeit gar nicht.
 */
it('laesst ein unerreichbares Ziel beschreibbar bleiben', function () {
    $fremd = new FakeRemoteCalendar;
    $fremd->antworten['calendar.kinds.list'] = RemoteResult::failed('Zeitueberschreitung', 'unreachable');

    app(EventSourceRegistry::class)->register(new RemoteEventSource($fremd));

    $this->actingAs($this->person)
        ->getJson('api/calendar/sources/verwaltung/kinds')
        ->assertStatus(502);

    expect(array_keys(app(EventSourceRegistry::class)->writable()))->toContain('verwaltung');
});

it('macht aus einem Fehlschlag drueben 502 und nennt Quelle und Vorgang', function () {
    // Nicht 500: Diese Anwendung ist in Ordnung, die andere hat nicht
    // mitgespielt. Und „etwas ist schiefgegangen" schickt den naechsten
    // Menschen auf die falsche Suche.
    WritableDemoSource::$scheitert = true;

    $this->actingAs($this->person)
        ->deleteJson('api/calendar/sources/writable-demo/events/4711')
        ->assertStatus(502)
        ->assertJsonPath('source', 'writable-demo')
        ->assertJsonPath('operation', 'loeschen');
});
