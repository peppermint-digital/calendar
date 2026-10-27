<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Peppermint\Calendar\Brain\KalenderFreigabe;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
use Peppermint\Calendar\Sources\BrainPrivateEventSource;
use Peppermint\Calendar\Sources\ErklaertAbwesenheit;

/**
 * Die privaten Termine aus der Nabe — angeboten nur, wer sie freigeschaltet hat.
 *
 * ## Woher das kommt
 *
 * Die Kalender-Werkzeuge der Nabe hängen an einer persönlichen Freischaltung
 * (`hasPluginCapability('calendar')`). Wer sie nicht hat, bekommt sie gar nicht
 * angemeldet — und der Abruf endete in einer `WARNING` je Seitenaufruf.
 *
 * Nachgemessen am 27.10.2026 am Produktionslog des Peppermint Manager —
 * eine frühere Angabe („144 Zeilen an einem Tag") zählte ZEILEN statt
 * Vorfällen und warf zwei Tage zusammen:
 *
 *   **73 Vorfälle** — 32 am 26.10., 41 am 27.10.
 *   vier Personen: 34/26/9/4 (userId 5, 16, 10, 9)
 *   Spitze 26 um 12 Uhr
 *
 * Kein Zeitplan, sondern Menschen, die auf ihren Kalender schauen.
 *
 * Je Vorfall entstehen ZWEI Zeilen, 146 insgesamt — und das ist der Punkt:
 * nicht nur eine `WARNING`, sondern davor eine `ERROR` der Brücke („Tool
 * [list-calendar-bundle-tool] not found"). Halb so viele Vorfälle wie zuerst
 * gemeldet, aber sie standen im FEHLERlog. Kaputt war keiner davon.
 *
 * ## Was hier geprüft wird, und was nicht
 *
 * Nicht der Komm-Layer — der gehört der Brücke, und sie ist hier nicht
 * installiert (`suggest`, Regel 7). Geprüft wird, was das Paket selbst
 * verantwortet: dass die Quelle ohne Freischaltung verschwindet, dass sie mit
 * ihr erscheint, dass die Frage je Person nur EINMAL ins Netz geht, und dass
 * ein Ausfall nicht als Nichtwahl erscheint.
 *
 * Der dritte Punkt ist der eigentliche Zweck: Ein Riegel, der je Seitenaufruf
 * einen Abruf kostet, hätte die 73 nur umbenannt.
 */
beforeEach(function () {
    Cache::flush();
});

/**
 * Eine Freigabe-Prüfung, die eine Werkzeugliste vortäuscht UND mitzählt.
 *
 * Überschrieben wird `werkzeugnamen()` und NICHT `fuerAngemeldetePerson()` —
 * damit Zwischenspeicher und Auswertung die echten bleiben. Ein Doppel, das
 * die Antwort direkt liefert, würde genau das nicht prüfen, worum es geht.
 */
function freigabeMitWerkzeugen(array $namen, ?Throwable $fehler = null): KalenderFreigabe
{
    return new class($namen, $fehler) extends KalenderFreigabe
    {
        public int $abrufe = 0;

        public function __construct(private array $namen, private ?Throwable $fehler)
        {
            parent::__construct(null);
        }

        protected function werkzeugnamen(): array
        {
            $this->abrufe++;

            if ($this->fehler !== null) {
                throw $this->fehler;
            }

            return $this->namen;
        }

        // Die Person kommt in dieser Suite nicht aus einer Wache mit Modell.
        // `public`, weil die Quelle dieselbe Frage hier stellt — sie ist seit
        // dem Zusammenlegen die EINE Stelle dafuer.
        public function personId(): int|string|null
        {
            return Auth::id() ?? 42;
        }
    };
}

/**
 * Eine Quelle, die die Brücke als vorhanden ausgibt.
 *
 * Wer angemeldet ist, beantwortet die Freigabe-Prüfung — nicht die Quelle.
 * Das war erst anders, und dieser Test hat es gefunden: Die Quelle hatte eine
 * eigene Fassung, und der Hinweis „nicht verbunden" blieb deshalb aus.
 */
function quelleMitFreigabe(KalenderFreigabe $freigabe): BrainPrivateEventSource
{
    return new class($freigabe) extends BrainPrivateEventSource
    {
        public function __construct(KalenderFreigabe $freigabe)
        {
            parent::__construct($freigabe);
        }

        protected function brueckeVorhanden(): bool
        {
            return true;
        }
    };
}

it('bietet die Quelle NICHT an, wenn die Person den Kalender nicht freigeschaltet hat', function () {
    $quelle = quelleMitFreigabe(freigabeMitWerkzeugen(['list-tasks-tool']));

    expect($quelle->isAvailable())->toBeFalse();
});

it('bietet sie an, sobald die Freischaltung da ist', function () {
    $quelle = quelleMitFreigabe(freigabeMitWerkzeugen([KalenderFreigabe::WERKZEUG]));

    expect($quelle->isAvailable())->toBeTrue();
});

it('fragt die Freischaltung je Person nur EINMAL — sonst waeren die 73 nur umbenannt', function () {
    $freigabe = freigabeMitWerkzeugen(['list-tasks-tool']);
    $quelle = quelleMitFreigabe($freigabe);

    // Fuenf Aufrufe, wie bei fuenf Seitenaufrufen hintereinander.
    for ($i = 0; $i < 5; $i++) {
        $quelle->isAvailable();
    }

    expect($freigabe->abrufe)->toBe(1, 'Der Zwischenspeicher muss greifen.');
});

it('merkt einen Ausfall der Nabe NICHT', function () {
    // Sonst bliebe die Quelle nach einem kurzen Aussetzer noch eine Minute
    // verborgen, obwohl die Nabe laengst wieder da ist.
    $freigabe = freigabeMitWerkzeugen([], new RuntimeException('Nabe weg'));
    $quelle = quelleMitFreigabe($freigabe);

    expect($quelle->isAvailable())->toBeFalse()
        ->and($quelle->isAvailable())->toBeFalse()
        ->and($freigabe->abrufe)->toBe(2, 'Ein Fehlschlag wird erneut versucht.');
});

it('merkt eine fehlende Bruecke sehr wohl — die kommt nicht von selbst zurueck', function () {
    // Anders als ein Aussetzer: Ohne installierte Bruecke bleibt es so, bis
    // jemand sie installiert. Jeden Seitenaufruf anklopfen zu lassen waere
    // Verschwendung.
    $freigabe = freigabeMitWerkzeugen([], BrainBridgeMissing::forPrivateEvents());
    $quelle = quelleMitFreigabe($freigabe);

    expect($quelle->isAvailable())->toBeFalse()
        ->and($quelle->isAvailable())->toBeFalse()
        ->and($freigabe->abrufe)->toBe(1, 'Eine fehlende Bruecke wird gemerkt.');
});

it('erklaert der Oberflaeche, warum die Quelle fehlt', function () {
    $quelle = quelleMitFreigabe(freigabeMitWerkzeugen(['list-tasks-tool']));

    expect($quelle)->toBeInstanceOf(ErklaertAbwesenheit::class);

    $hinweis = $quelle->abwesenheitsHinweis();

    expect($hinweis)->toBeArray()
        ->and($hinweis['titel'])->toBe('AI-Brain-Kalender nicht verbunden')
        // Der Text darf NICHT zum Selbst-Freischalten auffordern: Die Zuweisung
        // liegt in der Nabe hinter einem Verwaltungsrecht.
        ->and($hinweis['text'])->toContain('Administrator');
});

it('erklaert nichts, wenn alles in Ordnung ist', function () {
    $quelle = quelleMitFreigabe(freigabeMitWerkzeugen([KalenderFreigabe::WERKZEUG]));

    expect($quelle->abwesenheitsHinweis())->toBeNull();
});

it('erklaert NICHTS, wenn das Produkt die Quelle abgeschaltet hat', function () {
    // Wer eine Quelle nicht einschalten kann, muss nicht erfahren, dass es sie
    // gibt.
    config()->set('calendar.brain_private_events.enabled', false);

    $quelle = quelleMitFreigabe(freigabeMitWerkzeugen(['list-tasks-tool']));

    expect($quelle->isAvailable())->toBeFalse()
        ->and($quelle->abwesenheitsHinweis())->toBeNull();
});

it('ist still nicht verfuegbar, wenn die Bruecke fehlt — und meldet sich erst beim Benutzen', function () {
    // Gegenprobe zur Probe: Die Bruecke ist in dieser Suite wirklich nicht da.
    // Ohne diese Zeile prueft der Test moeglicherweise nichts.
    expect(class_exists('\\Peppermint\\AiBrainBridge\\Facades\\AiBrain'))->toBeFalse();

    $quelle = new BrainPrivateEventSource(new KalenderFreigabe);

    expect($quelle->isAvailable())->toBeFalse('still, nicht laut')
        ->and($quelle->abwesenheitsHinweis())->toBeNull('und ohne Hinweis');

    // Wer sie trotzdem ausdruecklich benutzt, bekommt eine Meldung, die sagt
    // WAS fehlt — statt eines `Class not found` aus der Tiefe einer Quelle.
    expect(fn () => $quelle->kinds())->toThrow(BrainBridgeMissing::class);
});

it('nennt in der Meldung das fehlende Paket und den Weg daran vorbei', function () {
    try {
        (new BrainPrivateEventSource(new KalenderFreigabe))->kinds();
        $this->fail('Es haette werfen muessen.');
    } catch (BrainBridgeMissing $fehler) {
        expect($fehler->getMessage())
            ->toContain('peppermint/ai-brain-bridge')
            ->toContain('brain_private_events');
    }
});

it('oeffnet keinen fremden Kalender — auch nicht fuer einen Administrator', function () {
    /*
    | Die Grenze, die der Umzug ins Paket nicht verlieren durfte.
    |
    | Im Manager hing sie an Bug #657: Ein Administrator beim
    | Kalender-Umschalten bekam sonst den Privatkalender einer anderen Person.
    | Die Quelle liefert ausschliesslich die Termine der ANGEMELDETEN Person,
    | und ein fremder `$userId` fuehrt zu einer leeren Liste — nicht zu einem
    | Abruf mit fremder Nummer.
    */
    $quelle = quelleMitFreigabe(freigabeMitWerkzeugen([KalenderFreigabe::WERKZEUG]));

    // Die Doppel-Person ist 42; hier wird nach 99 gefragt.
    expect($quelle->events(99, now()->subDay(), now()->addDay()))->toBe([]);
});
