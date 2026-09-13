<?php

use Carbon\CarbonImmutable;
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
