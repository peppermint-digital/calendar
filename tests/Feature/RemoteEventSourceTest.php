<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Peppermint\Calendar\Exceptions\ExternalSourceFailed;
use Peppermint\Calendar\Sources\NewExternalEvent;
use Peppermint\Calendar\Sources\RemoteEventSource;
use Peppermint\Calendar\Sources\RemoteResult;
use Peppermint\Calendar\Tests\Fixtures\FakeRemoteCalendar;

beforeEach(function () {
    $this->fremd = new FakeRemoteCalendar;
    $this->quelle = new RemoteEventSource($this->fremd);
});

/**
 * Eine angemeldete Person, ohne Benutzertabelle.
 *
 * `GenericUser` und kein Eloquent-Modell: Eloquent erzeugt sich fuer seine
 * Ereignisse selbst neu (`new static`), und ein Modell mit Pflichtargument
 * scheitert daran — an einer Stelle, die mit dem Geprueften nichts zu tun hat.
 */
function angemeldet(int $id = 7): void
{
    Auth::setUser(new GenericUser(['id' => $id]));
}

it('liest beide Formen des Zeitfeldes', function () {
    // Der Manager hat sein Antwortformat einmal umgestellt (start/end nach
    // starts_at/ends_at). Wer nur eine Form liest, wird durch einen Deploy auf
    // der anderen Seite blind — und zwar lautlos.
    $this->fremd->antworten['calendar.events.list'] = RemoteResult::ok([
        ['id' => 1, 'title' => 'Neue Form', 'starts_at' => '2026-10-13T09:00:00+02:00', 'ends_at' => '2026-10-13T10:00:00+02:00'],
        ['id' => 2, 'title' => 'Alte Form', 'start' => '2026-10-13T11:00:00+02:00', 'end' => '2026-10-13T12:00:00+02:00'],
    ]);

    $termine = $this->quelle->events(7, CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-14'));

    expect($termine)->toHaveCount(2)
        ->and($termine[0]->title)->toBe('Neue Form')
        ->and($termine[1]->startsAt->format('H:i'))->toBe('11:00');
});

it('reicht fremde Zusatzfelder durch, ohne die Kernfelder zu verdoppeln', function () {
    // Die Quelle darf keinen Feldnamen des anderen Systems kennen — sonst ist
    // sie wieder der Sonderclient. Also wandert alles weiter, was nicht schon
    // in ein Kernfeld geflossen ist. Die Aliasse muessen mit heraus, sonst
    // stuende die Zeit zweimal drin.
    $this->fremd->antworten['calendar.events.list'] = RemoteResult::ok([[
        'id' => 1,
        'title' => 'Kundenbesuch',
        'start' => '2026-10-13T09:00:00+02:00',
        'end' => '2026-10-13T10:00:00+02:00',
        'ticket' => 'TIC-4711',
        'abrechenbar' => false,
        'leer' => null,
    ]]);

    $extra = $this->quelle->events(7, CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-14'))[0]->extra;

    expect($extra)->toBe(['ticket' => 'TIC-4711', 'abrechenbar' => false])
        ->and($extra)->not->toHaveKey('start')
        ->and($extra)->not->toHaveKey('leer');
});

it('zeigt nichts, wenn die Person im anderen System nicht aufloesbar ist', function () {
    // Fail-closed. Ein Kalender, der bei unbekannter Identitaet den ganzen
    // Team-Kalender zeigt, war Bug #657.
    $this->fremd->kennung = null;
    $this->fremd->antworten['calendar.events.list'] = RemoteResult::ok([
        ['id' => 1, 'title' => 'Fremd', 'starts_at' => '2026-10-13T09:00:00+02:00'],
    ]);

    expect($this->quelle->events(7, CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-14')))->toBe([])
        ->and($this->fremd->aufrufe)->toBe([]);
});

it('laesst Termine anderer Personen draussen', function () {
    // Zweiter Riegel neben dem des Werkzeugs drueben. Fehlt die Angabe, bleibt
    // der Termin drin — ein Produkt, das keinen Eigner nennt, nennt ohnehin nur
    // die Termine der handelnden Person.
    $this->fremd->antworten['calendar.events.list'] = RemoteResult::ok([
        ['id' => 1, 'title' => 'Meiner', 'starts_at' => '2026-10-13T09:00:00+02:00', 'user_email' => 'PERSON@beispiel.de'],
        ['id' => 2, 'title' => 'Fremder', 'starts_at' => '2026-10-13T11:00:00+02:00', 'user_email' => 'andere@beispiel.de'],
        ['id' => 3, 'title' => 'Ohne Angabe', 'starts_at' => '2026-10-13T13:00:00+02:00'],
    ]);

    $titel = array_map(
        fn ($t) => $t->title,
        $this->quelle->events(7, CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-14')),
    );

    // Gross-/Kleinschreibung darf nicht entscheiden: Adressen sind im
    // Lokalteil theoretisch unterscheidbar, in der Praxis nie — und ein
    // Termin, der daran verschwindet, ist nicht erklaerbar.
    expect($titel)->toBe(['Meiner', 'Ohne Angabe']);
});

it('wirft beim Lesen, statt eine leere Liste zu liefern', function () {
    // Ein Ausfall ist kein leeres Ergebnis. Die Registrierung faengt den Wurf,
    // protokolliert und laesst die Quelle weg — der Rest des Kalenders bleibt
    // sichtbar. Eine leere Liste waere von „keine Termine" nicht zu
    // unterscheiden.
    $this->fremd->antworten['calendar.events.list'] = RemoteResult::failed('Zeitueberschreitung', 'unreachable');

    expect(fn () => $this->quelle->events(7, CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-14')))
        ->toThrow(RuntimeException::class, 'Verwaltung nicht erreichbar: Zeitueberschreitung');
});

it('schlaegt beim Anlegen laut fehl und nennt den Vorgang', function () {
    // Zwischen „Seite geoeffnet" und „Speichern gedrueckt" liegen Minuten. Ein
    // Termin, den jemand angelegt zu haben glaubt und den es nirgends gibt,
    // ist schlimmer als eine Fehlermeldung — das ist der ungeprueft gebliebene
    // Riegel aus AI Brain #5729.
    $this->fremd->antworten['calendar.events.create'] = RemoteResult::failed('Dienst weg', 'unreachable');

    $entwurf = new NewExternalEvent(
        kind: 'verwaltung_termin',
        title: 'Kundenbesuch',
        startsAt: CarbonImmutable::parse('2026-10-13T09:00:00+02:00'),
        endsAt: CarbonImmutable::parse('2026-10-13T10:00:00+02:00'),
    );

    try {
        $this->quelle->create(7, $entwurf);
        $this->fail('Ein Fehlschlag beim Schreiben muss durchschlagen.');
    } catch (ExternalSourceFailed $e) {
        expect($e->vorgang)->toBe('anlegen')
            ->and($e->sourceKey)->toBe('verwaltung')
            ->and($e->getMessage())->toContain('Dienst weg');
    }
});

it('behaelt die neue Position erst, wenn das Zielsystem sie bestaetigt', function () {
    // Anders als beim Anlegen ist die Antwort hier nicht Beiwerk: Kommt nichts
    // Lesbares zurueck, ist das ein Fehlschlag und keine Gelegenheit, die
    // gezogene Fassung zu zeigen.
    $this->fremd->antworten['calendar.events.update'] = RemoteResult::ok([]);

    expect(fn () => $this->quelle->move(
        7,
        '4711',
        CarbonImmutable::parse('2026-10-13T09:00:00+02:00'),
        CarbonImmutable::parse('2026-10-13T10:00:00+02:00'),
    ))->toThrow(ExternalSourceFailed::class, 'nichts Lesbares');
});

it('schickt all_day nur mit, wenn es sich aendert', function () {
    // Wer hier immer `false` sendete, machte aus jedem Ganztagstermin beim
    // Verschieben einen Zeittermin.
    $this->fremd->antworten['calendar.events.update'] = RemoteResult::ok([
        'id' => '4711', 'title' => 'Verschoben', 'starts_at' => '2026-10-13T09:00:00+02:00',
    ]);

    $this->quelle->move(7, '4711', CarbonImmutable::parse('2026-10-13T09:00:00+02:00'), CarbonImmutable::parse('2026-10-13T10:00:00+02:00'));

    expect($this->fremd->letzterAufruf('calendar.events.update'))->not->toHaveKey('all_day');

    $this->quelle->move(7, '4711', CarbonImmutable::parse('2026-10-13T09:00:00+02:00'), CarbonImmutable::parse('2026-10-13T10:00:00+02:00'), allDay: false);

    expect($this->fremd->letzterAufruf('calendar.events.update')['all_day'])->toBeFalse();
});

it('bestaetigt das Loeschen ausdruecklich', function () {
    // Ohne `confirm` behandeln loeschende Werkzeuge den Aufruf als Vorschau und
    // melden Erfolg, ohne etwas zu tun.
    $this->fremd->antworten['calendar.events.delete'] = RemoteResult::ok();

    $this->quelle->delete(7, '4711');

    expect($this->fremd->letzterAufruf('calendar.events.delete'))->toBe([
        'calendar_event_id' => '4711',
        'confirm' => true,
    ]);
});

it('ist nicht beschreibbar, wenn eine der drei Faehigkeiten fehlt', function () {
    // Drei und nicht eine: Ein System, das nur anlegen laesst, waere
    // beschreibbar fuer einen Knopf und nicht fuer die anderen zwei.
    angemeldet();

    expect($this->quelle->isWritable())->toBeTrue();

    $this->fremd->faehigkeiten = array_values(array_diff($this->fremd->faehigkeiten, ['calendar.events.delete']));

    expect($this->quelle->isWritable())->toBeFalse();
});

it('ist ohne angemeldete Person nicht beschreibbar', function () {
    // Ein Hintergrundlauf soll nicht im Namen irgendwessen in fremde Systeme
    // schreiben. Und der Riegel sitzt hier, nicht beim Erfueller des Vertrags:
    // Wer eine 0 weiterreicht, verlaesst sich darauf, dass jede Umsetzung sie
    // als „niemand" liest.
    expect($this->quelle->isWritable())->toBeFalse();
});

it('verschwindet, wenn das andere System seinen Kalender nicht mehr herausgibt', function () {
    // Eine Quelle, die von selbst verschwindet, ist die Antwort auf „nicht
    // freigeschaltet" — sie braucht keine zweite Pflegestelle.
    expect($this->quelle->isAvailable())->toBeTrue();

    $this->fremd->faehigkeiten = [];

    expect($this->quelle->isAvailable())->toBeFalse()
        ->and($this->quelle->isWritable())->toBeFalse();
});

it('laesst Arten weg, die man drueben nicht von Hand anlegen darf', function () {
    // Die Ticket-Zeitblock-Art der Verwaltung entsteht aus einem Ticket. Wer
    // sie zur Auswahl stellt, baut eine Maske, die beim Speichern abgelehnt
    // wird — und das anbietende System entscheidet das, nicht das lesende.
    $this->fremd->antworten['calendar.kinds.list'] = RemoteResult::ok([
        ['key' => 'verwaltung_termin', 'label' => 'Termin', 'requires' => ['title'], 'creatable' => true],
        ['key' => 'ticket_zeitblock', 'label' => 'Zeitblock', 'creatable' => false],
        ['key' => 'ohne_angabe', 'label' => 'Ohne Angabe'],
    ]);

    $arten = array_map(fn ($a) => $a->key, $this->quelle->kinds());

    expect($arten)->toBe(['verwaltung_termin', 'ohne_angabe']);
});

it('nennt keine Arten, wenn das andere System den Vorgang nicht anbietet', function () {
    // Raten waere die schlechtere Antwort: Eine falsche Art legt den Termin in
    // die falsche Schublade, und das faellt erst drueben auf.
    $this->fremd->faehigkeiten = ['calendar.events.list'];

    expect($this->quelle->kinds())->toBe([])
        ->and($this->fremd->aufrufe)->toBe([]);
});

it('nimmt die Adresse aus der Antwort und erfindet sie nur im Notfall', function () {
    // Das Produkt kennt seine eigenen Wege. Ein geratener Pfad zeigte beim
    // Manager auf `/tasks/{id}` und waere fuer die Verwaltung falsch gewesen.
    config()->set('calendar.source_event_paths.verwaltung', '/kalender/termine/{id}');

    $this->fremd->antworten['calendar.events.list'] = RemoteResult::ok([
        ['id' => 1, 'title' => 'Mit Adresse', 'starts_at' => '2026-10-13T09:00:00+02:00', 'url' => 'https://vw.beispiel.de/tickets/9'],
        ['id' => 2, 'title' => 'Ohne Adresse', 'starts_at' => '2026-10-13T11:00:00+02:00'],
    ]);

    $termine = $this->quelle->events(7, CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-14'));

    expect($termine[0]->url)->toBe('https://vw.beispiel.de/tickets/9')
        ->and($termine[1]->url)->toBe('https://vw.beispiel.de/kalender/termine/2');
});

it('bleibt ohne Muster und ohne Wurzel lieber ohne Sprungziel', function () {
    // Besser kein Link als ein geratener.
    $this->fremd->wurzel = null;
    $this->fremd->antworten['calendar.events.list'] = RemoteResult::ok([
        ['id' => 2, 'title' => 'Ohne Adresse', 'starts_at' => '2026-10-13T11:00:00+02:00'],
    ]);

    expect($this->quelle->events(7, CarbonImmutable::parse('2026-10-13'), CarbonImmutable::parse('2026-10-14'))[0]->url)->toBeNull();
});
