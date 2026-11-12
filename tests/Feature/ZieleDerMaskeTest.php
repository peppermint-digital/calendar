<?php

use Peppermint\Calendar\Sources\EventSource;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Sources\MeldetAusfall;
use Peppermint\Calendar\Sources\WritableEventSource;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;
use Peppermint\Calendar\Tests\Fixtures\WritableDemoSource;

/*
| Bug #1417: Die Ziele der Anlege-Maske kommen aus dem PAKET.
|
| `EventSourceRegistry::writable()` gab es schon — mit der Begruendung im
| Docblock direkt darueber („wer die Pruefung an jeder Bedienstelle
| wiederholt, vergisst sie irgendwo"). Gemessen am 12.11.2026 rief es
| NIEMAND: nicht das Paket, nicht eines der drei Produkte, nicht die
| Testreihe. Daneben stand `beschreibbareZiele()` als Handkopie in drei
| Produkten, die stattdessen selbst mit `method_exists()` filterte.
|
| `ziele()` ist deshalb kein neuer Weg, sondern der Anschluss des gebauten.
| Und es kann etwas, das keine Handkopie konnte: ein AUSGEFALLENES Ziel
| markieren statt es wegzufiltern.
*/

/** Eine beschreibbare Quelle, die zusaetzlich einen Ausfall melden kann. */
function quelleMitAusfall(bool $ausgefallen, bool $beschreibbar = false): WritableEventSource
{
    return new class($ausgefallen, $beschreibbar) extends WritableDemoSource implements MeldetAusfall
    {
        public function __construct(private bool $ausgefallen, private bool $beschreibbar) {}

        public function key(): string
        {
            return 'nabe';
        }

        public function label(): string
        {
            return 'AI Brain';
        }

        public function isWritable(): bool
        {
            return $this->beschreibbar;
        }

        public function istAusgefallen(): bool
        {
            return $this->ausgefallen;
        }
    };
}

it('nennt eine beschreibbare Quelle als Ziel', function () {
    $verzeichnis = new EventSourceRegistry;
    $verzeichnis->register(new WritableDemoSource);

    WritableDemoSource::$writable = true;

    expect($verzeichnis->ziele())->toBe([[
        'key' => 'writable-demo',
        'label' => 'Writable demo system',
        'writable' => true,
        'unreachable' => false,
    ]]);
});

it('laesst eine Quelle weg, die ordentlich nein sagt', function () {
    // Das soll so bleiben: Behoben wird, dass ein AUSFALL aussieht wie ein
    // Nein — nicht, dass ein Nein verschwindet.
    $verzeichnis = new EventSourceRegistry;
    $verzeichnis->register(quelleMitAusfall(ausgefallen: false, beschreibbar: false));

    expect($verzeichnis->ziele())->toBe([]);
});

it('laesst eine nur lesende Quelle weg', function () {
    // Eine Quelle, die `WritableEventSource` gar nicht ist, hat die Methode
    // nicht und kann nie Ziel sein. Vorher pruefte das Produkt das mit
    // `method_exists()` — dreimal, von Hand.
    $verzeichnis = new EventSourceRegistry;
    $verzeichnis->register(new DemoSource);

    expect($verzeichnis->ziele())->toBe([]);
});

it('MARKIERT ein ausgefallenes Ziel, statt es wegzulassen', function () {
    $verzeichnis = new EventSourceRegistry;
    $verzeichnis->register(quelleMitAusfall(ausgefallen: true, beschreibbar: false));

    expect($verzeichnis->ziele())->toBe([[
        'key' => 'nabe',
        'label' => 'AI Brain',
        // NICHT beschreibbar, und das ist Absicht: Waere es `true`, wuerde das
        // Ziel waehlbar und das Anlegen scheiterte erst beim Speichern. Ein
        // Knopf, der drueben scheitert, ist schlechter als kein Knopf
        // (Wiki #595) — und damit schlechter als der Zustand vor der Behebung.
        'writable' => false,
        'unreachable' => true,
    ]]);
});

it('kommt an die ausgefallene Quelle heran, obwohl available() sie wegfiltert', function () {
    // Der Kern des Fehlers: `isAvailable()` ist bei einer unerreichbaren Nabe
    // `false`, und `available()` haette sie entfernt, BEVOR irgendwer sie
    // fragen kann. Deshalb laeuft `ziele()` ueber `all()`.
    $verzeichnis = new EventSourceRegistry;

    $quelle = new class extends WritableDemoSource implements MeldetAusfall
    {
        public function key(): string
        {
            return 'nabe';
        }

        public function label(): string
        {
            return 'AI Brain';
        }

        public function isAvailable(): bool
        {
            return false;
        }

        public function isWritable(): bool
        {
            return false;
        }

        public function istAusgefallen(): bool
        {
            return true;
        }
    };

    $verzeichnis->register($quelle);

    expect($verzeichnis->available())->toBe([], 'Gegenprobe: available() sieht sie wirklich nicht.');
    expect($verzeichnis->ziele())->toHaveCount(1);
    expect($verzeichnis->ziele()[0]['unreachable'])->toBeTrue();
});

it('zaehlt eine beschreibbare Quelle NICHT als ausgefallen, auch wenn sie es meldet', function () {
    // Beschreibbar schlaegt die Ausfall-Meldung: Wenn sie schreiben kann, ist
    // sie da. Ein Eintrag mit `writable: true` UND `unreachable: true` waere
    // widerspruechlich, und die Oberflaeche wuerde ihn in beide Listen legen.
    $verzeichnis = new EventSourceRegistry;
    $verzeichnis->register(quelleMitAusfall(ausgefallen: true, beschreibbar: true));

    expect($verzeichnis->ziele())->toBe([[
        'key' => 'nabe',
        'label' => 'AI Brain',
        'writable' => true,
        'unreachable' => false,
    ]]);
});

it('fuehrt beide Arten von Zielen in EINER Liste', function () {
    $verzeichnis = new EventSourceRegistry;

    WritableDemoSource::$writable = true;
    $verzeichnis->register(new WritableDemoSource);
    $verzeichnis->register(quelleMitAusfall(ausgefallen: true, beschreibbar: false));
    $verzeichnis->register(new DemoSource);

    $ziele = $verzeichnis->ziele();

    expect($ziele)->toHaveCount(2)
        ->and(array_column($ziele, 'key'))->toBe(['writable-demo', 'nabe'])
        ->and(array_column($ziele, 'writable'))->toBe([true, false])
        ->and(array_column($ziele, 'unreachable'))->toBe([false, true]);
});

it('bleibt bei einer Quelle still, die den Ausfall-Vertrag nicht erfuellt', function () {
    // „Ich weiss es nicht" ist eine eigene Antwort und bei einem fremden
    // Produkt die Wahrheit (#1416): `RemoteEventSource::isWritable()` liest
    // eine LOKALE Faehigkeitsliste und kennt die Erreichbarkeit gar nicht.
    // Eine Quelle ohne den Vertrag darf deshalb nicht als ausgefallen gelten.
    $verzeichnis = new EventSourceRegistry;

    WritableDemoSource::$writable = false;
    $verzeichnis->register(new WritableDemoSource);

    expect($verzeichnis->ziele())->toBe([]);

    WritableDemoSource::$writable = true;
});
