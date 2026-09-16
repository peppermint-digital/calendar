<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Exceptions\ExternalSourceFailed;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Sources\NewExternalEvent;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;
use Peppermint\Calendar\Tests\Fixtures\WritableDemoSource;

beforeEach(function () {
    DemoSource::$available = true;
    DemoSource::$throws = false;
    DemoSource::$extra = [];
    WritableDemoSource::$writable = true;
    WritableDemoSource::$received = [];
    WritableDemoSource::$verschoben = [];
    WritableDemoSource::$geloescht = [];
    WritableDemoSource::$scheitert = false;

    config()->set('calendar.sources', [DemoSource::class, WritableDemoSource::class]);
    app()->forgetInstance(EventSourceRegistry::class);
});

it('trennt lesende von schreibenden Quellen', function () {
    // Der Grund fuer eine eigene Klasse statt eines Schalters: Eine lesende
    // Quelle hat die Methode gar nicht, also kann sie niemand versehentlich
    // benutzen.
    $writable = app(EventSourceRegistry::class)->writable();

    expect(array_keys($writable))->toBe(['writable-demo'])
        ->and(array_keys(app(EventSourceRegistry::class)->available()))
        ->toContain('demo');
});

it('laesst eine Quelle sagen, dass gerade niemand anlegen darf', function () {
    // Erreichbar und trotzdem nicht beschreibbar: Das Zielsystem bietet den
    // Vorgang nicht an, oder die handelnde Person hat dort keine Rechte. Die
    // Oberflaeche soll den Knopf dann gar nicht erst zeigen.
    WritableDemoSource::$writable = false;

    expect(app(EventSourceRegistry::class)->writable())->toBe([]);
});

it('nennt die Terminarten des anderen Systems samt Pflichtfeldern', function () {
    // Arten sind produkteigen — wer drueben anlegen will, muss eine auswaehlen
    // koennen statt sie zu raten.
    $kinds = app(EventSourceRegistry::class)->writable()['writable-demo']->kinds();

    expect($kinds[0]->toArray())->toBe([
        'key' => 'business',
        'label' => 'Business',
        'requires' => ['location'],
    ]);
});

it('zeigt die Fassung des Zielsystems, nicht die eigene', function () {
    // Das andere System rundet, ergaenzt Vorgaben, haengt seine Kennung an.
    // Angezeigt werden muss seine Antwort — sonst steht im Kalender etwas
    // anderes als drueben.
    $angelegt = app(EventSourceRegistry::class)->writable()['writable-demo']->create(1, new NewExternalEvent(
        kind: 'business',
        title: 'Kundentermin',
        startsAt: CarbonImmutable::parse('2026-09-14 09:17'),
        endsAt: CarbonImmutable::parse('2026-09-14 10:17'),
        location: 'Raum 2',
        extra: ['project_id' => 3],
    ));

    expect($angelegt->toArray()['id'])->toBe('writable-demo:99')
        ->and($angelegt->startsAt->format('H:i'))->toBe('09:00')
        ->and($angelegt->toArray()['extra'])->toBe(['project_id' => 3])
        ->and(WritableDemoSource::$received[0]->kind)->toBe('business');
});

it('schickt nur mit, was gesetzt ist', function () {
    // Ein Feld, das als null hinausgeht, ist fuer das Zielsystem nicht dasselbe
    // wie ein Feld, das gar nicht kommt — manche lesen es als „loeschen".
    $payload = (new NewExternalEvent(
        kind: 'business',
        title: 'Kurz',
        startsAt: CarbonImmutable::parse('2026-09-14 09:00'),
        endsAt: CarbonImmutable::parse('2026-09-14 10:00'),
    ))->toArray();

    expect($payload)->not->toHaveKey('location')
        ->and($payload)->not->toHaveKey('description')
        ->and($payload)->not->toHaveKey('extra')
        ->and($payload['kind'])->toBe('business');
});

it('verschiebt einen Termin und zeigt die Fassung des Zielsystems', function () {
    // Der haeufigste Eingriff ueberhaupt: ziehen im Raster. Zurueck kommt,
    // was das andere System daraus gemacht hat — hier gerundet.
    $verschoben = app(EventSourceRegistry::class)->writable()['writable-demo']->move(
        1,
        '99',
        CarbonImmutable::parse('2026-09-15 14:23'),
        CarbonImmutable::parse('2026-09-15 15:23'),
    );

    expect($verschoben->startsAt->format('Y-m-d H:i'))->toBe('2026-09-15 14:00')
        ->and(WritableDemoSource::$verschoben[0]['eventId'])->toBe('99');
});

it('nimmt den Wechsel zwischen Ganztagszeile und Raster mit', function () {
    // Ohne diesen Weg braeuchte die Oberflaeche einen zweiten fuers Ziehen in
    // die Ganztagszeile — und der kennt die Regeln irgendwann nicht mehr.
    app(EventSourceRegistry::class)->writable()['writable-demo']->move(
        1,
        '99',
        CarbonImmutable::parse('2026-09-15 00:00'),
        CarbonImmutable::parse('2026-09-16 00:00'),
        allDay: true,
    );

    expect(WritableDemoSource::$verschoben[0]['allDay'])->toBeTrue();
});

it('laesst unveraendert, was nicht mitgeschickt wird', function () {
    // `null` heisst „daran aendert sich nichts". Wer hier `false` schickte,
    // machte aus jedem Ganztagstermin beim Verschieben einen Zeittermin.
    app(EventSourceRegistry::class)->writable()['writable-demo']->move(
        1,
        '99',
        CarbonImmutable::parse('2026-09-15 09:00'),
        CarbonImmutable::parse('2026-09-15 10:00'),
    );

    expect(WritableDemoSource::$verschoben[0]['allDay'])->toBeNull();
});

it('loescht einen Termin drueben', function () {
    app(EventSourceRegistry::class)->writable()['writable-demo']->delete(1, '99');

    expect(WritableDemoSource::$geloescht)->toBe(['99']);
});

it('scheitert LAUT, wenn das andere System nicht mitspielt', function () {
    // Der Gegensatz zum Lesen: Dort verschluckt die Registry den Ausfall, weil
    // ein leerer Kalender schlimmer waere als ein unvollstaendiger. Beim
    // Schreiben hinterliesse dasselbe Verhalten einen Termin, den jemand
    // angelegt zu haben GLAUBT und den es nirgends gibt.
    WritableDemoSource::$scheitert = true;

    $quelle = app(EventSourceRegistry::class)->writable()['writable-demo'];

    expect(fn () => $quelle->move(1, '99', CarbonImmutable::now(), CarbonImmutable::now()->addHour()))
        ->toThrow(ExternalSourceFailed::class)
        ->and(fn () => $quelle->delete(1, '99'))
        ->toThrow(ExternalSourceFailed::class);
});

it('nennt in der Ausnahme, welcher Vorgang in welchem System scheiterte', function () {
    // Eine Meldung „Fehler" hilft niemandem. Die Oberflaeche muss sagen
    // koennen, WAS drueben nicht ging.
    WritableDemoSource::$scheitert = true;

    try {
        app(EventSourceRegistry::class)->writable()['writable-demo']->delete(1, '99');
        $ausnahme = null;
    } catch (ExternalSourceFailed $e) {
        $ausnahme = $e;
    }

    expect($ausnahme?->vorgang)->toBe('loeschen')
        ->and($ausnahme?->sourceKey)->toBe('writable-demo');
});
