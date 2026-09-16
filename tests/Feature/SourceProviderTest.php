<?php

use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;
use Peppermint\Calendar\Tests\Fixtures\DemoSourceProvider;
use Peppermint\Calendar\Tests\Fixtures\WritableDemoSource;

/*
| Quellen, die erst zur Laufzeit feststehen (#5729).
|
| Der Anlass: Angebundene Produkte melden ihre Faehigkeiten selbst. Ein neues
| Produkt soll im Kalender erscheinen, ohne dass jemand eine Klasse schreibt
| und eine Zeile in der Konfiguration ergaenzt — genau die Zeile vergisst
| sonst jemand, und das neue System taucht nirgends auf.
*/

beforeEach(function () {
    DemoSource::$available = true;
    WritableDemoSource::$writable = true;
    DemoSourceProvider::$liefert = [];
    DemoSourceProvider::$aufrufe = 0;
    app()->forgetInstance(EventSourceRegistry::class);
});

it('nimmt die Quellen eines Anbieters auf', function () {
    DemoSourceProvider::$liefert = [new DemoSource, new WritableDemoSource];
    config()->set('calendar.sources', [DemoSourceProvider::class]);

    expect(array_keys(app(EventSourceRegistry::class)->all()))
        ->toBe(['demo', 'writable-demo']);
});

it('mischt Anbieter und einzeln eingetragene Quellen', function () {
    // Beides nebeneinander muss gehen: Ein Produkt kann eine fest verdrahtete
    // Quelle haben und zusaetzlich welche, die zur Laufzeit dazukommen.
    DemoSourceProvider::$liefert = [new WritableDemoSource];
    config()->set('calendar.sources', [DemoSource::class, DemoSourceProvider::class]);

    expect(array_keys(app(EventSourceRegistry::class)->all()))
        ->toBe(['demo', 'writable-demo']);
});

it('fragt den Anbieter erst, wenn jemand die Registry braucht', function () {
    // Ein Anbieter fragt ein Register oder eine Datenbank. Wer ihn beim
    // Hochfahren aufruft, bezahlt die Abfrage auf jeder Seite — auch auf
    // jeder, die nie einen Termin zeigt.
    config()->set('calendar.sources', [DemoSourceProvider::class]);

    expect(DemoSourceProvider::$aufrufe)->toBe(0);

    app(EventSourceRegistry::class);

    expect(DemoSourceProvider::$aufrufe)->toBe(1);
});

it('weist zurueck, was keine Quelle ist', function () {
    DemoSourceProvider::$liefert = [new stdClass];
    config()->set('calendar.sources', [DemoSourceProvider::class]);

    expect(fn () => app(EventSourceRegistry::class))
        ->toThrow(InvalidArgumentException::class);
});
