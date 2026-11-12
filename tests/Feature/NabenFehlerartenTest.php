<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Peppermint\Calendar\Brain\KalenderFreigabe;
use Peppermint\Calendar\Enums\Freigabezustand;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
use Peppermint\Calendar\Exceptions\NabeLehntAb;
use Peppermint\Calendar\Sources\BrainPrivateEventSource;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Sources\MeldetAbsage;
use Peppermint\Calendar\Sources\MeldetAusfall;

/**
 * Die vier Fehlerarten des Komm-Layers, am Fangplatz unterschieden.
 *
 * ## Der Fall, um den es geht
 *
 * Aufgabe #9882. Bastian las in der Anlege-Maske des Managers:
 *
 *   „Nicht erreichbar: AI Brain (privat). Die Terminarten dieser Systeme
 *    fehlen in der Liste — die uebrigen sind vollstaendig."
 *
 * Die Nabe war aber erreichbar. Sie hat mit HTTP 401 geantwortet und
 * abgelehnt — die Reproduktionsschritte von Bug #1417 nennen genau diesen
 * Fall („leere OAuth-Zugangsdaten reichen, Aufruf endet in HTTP 401").
 *
 * Ursache: `KalenderFreigabe::erfragen()` fing jeden `Throwable` und gab
 * ausnahmslos {@see Freigabezustand::NichtErreichbar}, weil der HTTP-Status
 * nur im Meldungstext der Brueckenausnahme stand.
 *
 * ## Was NICHT gewechselt wurde, und warum das hierher gehoert
 *
 * Der TRANSPORT. Die Pruefung laeuft weiter ueber den Naben-Zweig des
 * Komm-Layers (`AiBrain::brain()`), nicht ueber `gateway()`. Gemessen am
 * 12.11.2026: Der Gateway loest jedes Ziel ueber ein `ConnectedProduct` mit
 * `mcp_url` auf, und die Nabe ist keines — `gateway(..., 'ai-brain')` haette
 * nie ein Ziel gefunden, und der Ausfall waere als „nicht erreichbar"
 * durchgegangen. Genau das Symptom, nur unbehebbar. Dieselbe Entscheidung
 * steht im Postfach-Paket ausgeschrieben
 * (`MailboxServiceProvider::mitteRufen()`, „call() und ausdruecklich NICHT
 * der Gateway") und wird von `BridgeWerkzeugquelle` so umgesetzt.
 */
beforeEach(function () {
    Cache::flush();
});

/**
 * Eine Freigabe-Pruefung mit einer vorgetaeuschten Werkzeugliste.
 *
 * Wie `freigabeMitWerkzeugen()` in `BrainPrivateEventsTest`, aber als eigene
 * Hilfsfunktion mit eigenem Namen: Zwei Dateien, die dieselbe globale
 * Pest-Hilfsfunktion erklaeren, brechen beim LADEN — und beide Dateien sind
 * dann einzeln gruen und zusammen gar nichts. Genau das ist in AI Brain zwei
 * Tage lang passiert (Wiki „Die zentrale Antwort existiert — gerufen wird sie
 * nicht", Richtung 3).
 */
function freigabeMitFehler(?Throwable $fehler, array $namen = []): KalenderFreigabe
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

        public function personId(): int|string|null
        {
            return Auth::id() ?? 42;
        }
    };
}

/*
|--------------------------------------------------------------------------
| Die vier Arten, je eine Zusicherung
|--------------------------------------------------------------------------
*/

it('BASTIANS FALL: eine Absage der Nabe ist NICHT „nicht erreichbar"', function () {
    // `not_allowed` — die Nabe hat geantwortet und abgelehnt.
    expect(freigabeMitFehler(new NabeLehntAb(401))->zustand())
        ->toBe(Freigabezustand::NichtErlaubt);

    Cache::flush();

    expect(freigabeMitFehler(new NabeLehntAb(403))->zustand())
        ->toBe(Freigabezustand::NichtErlaubt);

    // Und die Gegenprobe, ohne die die Zusicherung oben nichts wert waere:
    // Vor dieser Aenderung war die Antwort hier `NichtErreichbar`. Dass sie
    // es NICHT mehr ist, ist der ganze Befund.
    Cache::flush();

    expect(freigabeMitFehler(new NabeLehntAb(401))->zustand())
        ->not->toBe(Freigabezustand::NichtErreichbar);
});

it('ein AUSFALL bleibt „nicht erreichbar" — unveraendert', function () {
    // `unreachable`. Die Brueckenausnahme fuer einen Verbindungsausfall ist
    // ein eigener Typ (`ConnectionException`), hier steht ein beliebiger
    // anderer Fehler fuer dieselbe Lage: Was wir nicht deuten koennen, gilt
    // als Ausfall — die vorsichtige Richtung.
    expect(freigabeMitFehler(new RuntimeException('Nabe weg'))->zustand())
        ->toBe(Freigabezustand::NichtErreichbar);
});

it('ein WERKZEUGFEHLER gilt bewusst als Ausfall, nicht als Absage', function () {
    // `tool_failed` — die Nabe antwortet, aber mit 5xx oder einem
    // JSON-RPC-Fehler. Absichtlich derselbe Zustand wie ein Ausfall: Fuer die
    // Person ist es dieselbe Lage (sie kann nichts tun), und es darf genauso
    // NICHT gemerkt werden. Ein eigener Zustand haette einen eigenen Satz
    // gebraucht, der nichts anderes sagen koennte.
    //
    // Diese Zusicherung ist deshalb keine Beschreibung des Ist-Zustands,
    // sondern eine ENTSCHEIDUNG: Wer sie aendert, aendert sie absichtlich.
    expect(freigabeMitFehler(new RuntimeException('MCP tools/list failed: HTTP 500'))->zustand())
        ->toBe(Freigabezustand::NichtErreichbar);
});

it('eine fehlende Freischaltung bleibt eine Auskunft, kein Fehler', function () {
    // `not_offered` — die Nabe HAT geantwortet, das Werkzeug stand nur nicht
    // in ihrer Liste. Kein Fehlschlag, also kein Fehlerzweig.
    expect(freigabeMitFehler(null, ['list-tasks-tool'])->zustand())
        ->toBe(Freigabezustand::NichtFreigeschaltet);

    Cache::flush();

    // Und die Gegenprobe nach oben, damit „nicht freigeschaltet" nicht
    // einfach die Vorgabe ist: Mit dem Werkzeug ist sie frei.
    expect(freigabeMitFehler(null, [KalenderFreigabe::WERKZEUG])->zustand())
        ->toBe(Freigabezustand::Frei);
});

it('eine fehlende Bruecke ist keine der vier Arten', function () {
    // Sie entsteht, BEVOR irgendetwas ins Netz geht — es gibt keine
    // Gegenstelle, die antworten oder ablehnen koennte.
    expect(freigabeMitFehler(BrainBridgeMissing::forPrivateEvents())->zustand())
        ->toBe(Freigabezustand::BrueckeFehlt);
});

/*
|--------------------------------------------------------------------------
| Die Auflage aus #1417: der Fehlzustand wird NICHT gemerkt
|--------------------------------------------------------------------------
*/

it('merkt die ABSAGE nicht — sie behauptet etwas ueber Berechtigungen', function () {
    // Der Grund steht an `Freigabezustand::merkbar()`: Eine falsch gemerkte
    // Absage schickt die Person 60 Sekunden lang zu einer Administration, die
    // nichts zu tun hat — dieselbe Fehlleitung, die #1417 gerade abgeschafft
    // hat.
    $abgelehnt = freigabeMitFehler(new NabeLehntAb(403));

    // Der Zustand MIT, nicht nur die Zahl der Abrufe. Mit der Zahl allein war
    // dieser Test eine Zusicherung, die ihren eigenen Gegenstand nicht sieht:
    // `NichtErreichbar` wird ebenfalls nicht gemerkt, also blieb er gruen,
    // auch wenn die Absage wieder in den Ausfall zurueckfiel. Aufgefallen an
    // der Mutationsprobe zu dieser Aenderung — sie liess ihn als einzigen
    // stehen, und das war der Hinweis.
    expect($abgelehnt->zustand())->toBe(Freigabezustand::NichtErlaubt)
        ->and($abgelehnt->zustand())->toBe(Freigabezustand::NichtErlaubt);

    // ZWEI Abrufe: nichts gemerkt.
    expect($abgelehnt->abrufe)->toBe(2);

    Cache::flush();

    // Die Gegenprobe, und sie ist noetig: Eine Zusicherung auf „2" allein
    // kann „nicht gemerkt" nicht von „der Speicher tut gar nichts"
    // unterscheiden. Eine Auskunft WIRD gemerkt, also nur EIN Abruf.
    $frei = freigabeMitFehler(null, [KalenderFreigabe::WERKZEUG]);

    $frei->zustand();
    $frei->zustand();

    expect($frei->abrufe)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Die Quelle meldet die Absage — und ziele() fuehrt sie als EIGENES Merkmal
|--------------------------------------------------------------------------
*/

/** Eine Quelle, die genau den gegebenen Zustand meldet. */
function quelleMitZustand(Freigabezustand $zustand): BrainPrivateEventSource
{
    return new class(freigabeMitFehler(match ($zustand) {
        Freigabezustand::NichtErlaubt => new NabeLehntAb(403),
        Freigabezustand::NichtErreichbar => new RuntimeException('Nabe weg'),
        default => null,
    }, $zustand === Freigabezustand::Frei ? [KalenderFreigabe::WERKZEUG] : [])) extends BrainPrivateEventSource
    {
        // Die Bruecke ist in dieser Testreihe nicht installiert (Regel 7).
        // Ohne diese Ueberschreibung melden `istAbgelehnt()` und
        // `istAusgefallen()` beide `false`, und der Test waere gruen, ohne
        // den gemeinten Zweig je zu betreten.
        protected function brueckeVorhanden(): bool
        {
            return true;
        }
    };
}

it('die Quelle meldet die Absage ueber ihren eigenen Vertrag', function () {
    $quelle = quelleMitZustand(Freigabezustand::NichtErlaubt);

    expect($quelle)->toBeInstanceOf(MeldetAbsage::class)
        ->and($quelle->istAbgelehnt())->toBeTrue();
});

it('Absage und Ausfall sind nie zugleich wahr', function () {
    // Sonst traege der Zieleintrag zwei Markierungen, und die Oberflaeche
    // legte dasselbe System in zwei Listen. Dieselbe Begruendung, aus der ein
    // ausgefallenes Ziel nicht `writable: true` bekommt.
    $abgelehnt = quelleMitZustand(Freigabezustand::NichtErlaubt);

    expect($abgelehnt->istAbgelehnt())->toBeTrue()
        ->and($abgelehnt->istAusgefallen())->toBeFalse();

    Cache::flush();

    $ausgefallen = quelleMitZustand(Freigabezustand::NichtErreichbar);

    expect($ausgefallen->istAusgefallen())->toBeTrue()
        ->and($ausgefallen->istAbgelehnt())->toBeFalse();
});

it('ziele() markiert die Absage getrennt von unreachable', function () {
    $verzeichnis = new EventSourceRegistry;
    $verzeichnis->register(quelleMitZustand(Freigabezustand::NichtErlaubt));

    $ziele = $verzeichnis->ziele();

    expect($ziele)->toHaveCount(1)
        ->and($ziele[0]['not_allowed'])->toBeTrue()
        // Der eigentliche Befund: NICHT als unerreichbar. Waere das `true`,
        // stuende in der Maske wieder „Nicht erreichbar: AI Brain (privat)" —
        // die Luege waere nur von der Fehlerbehandlung in den Zieleintrag
        // umgezogen.
        ->and($ziele[0]['unreachable'])->toBeFalse()
        // Und nicht waehlbar: Ein Knopf, der drueben scheitert, ist
        // schlechter als kein Knopf (Wiki #595).
        ->and($ziele[0]['writable'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Der Satz, den ein Mensch liest
|--------------------------------------------------------------------------
*/

it('der Hinweis sagt „nicht freigegeben" und NICHT „nicht erreichbar"', function () {
    // Die Abnahme dieser Aufgabe, woertlich.
    $hinweis = quelleMitZustand(Freigabezustand::NichtErlaubt)->abwesenheitsHinweis();

    expect($hinweis)->not->toBeNull()
        ->and($hinweis['titel'])->toContain('nicht freigegeben')
        ->and($hinweis['text'])->toContain('abgelehnt')
        // Das Entscheidende ist, was NICHT darin steht:
        ->and($hinweis['titel'])->not->toContain('antwortet nicht')
        ->and($hinweis['text'])->not->toContain('nicht erreichbar')
        // Und er schickt niemanden zur Administration des eigenen Kontos —
        // bei 401/403 ist nicht die persoenliche Freischaltung das Problem.
        ->and($hinweis['text'])->not->toContain('freigeschaltet');
});

it('der Ausfall behaelt seinen eigenen, anderen Satz', function () {
    // Die Gegenprobe: Haetten beide denselben Text, waere die Trennung oben
    // ohne Wirkung — und genau dieser Zustand war der Fehler.
    $ausfall = quelleMitZustand(Freigabezustand::NichtErreichbar)->abwesenheitsHinweis();
    Cache::flush();
    $absage = quelleMitZustand(Freigabezustand::NichtErlaubt)->abwesenheitsHinweis();

    expect($ausfall['titel'])->toContain('antwortet nicht')
        ->and($ausfall['text'])->toContain('nicht erreichbar')
        ->and($absage['titel'])->not->toBe($ausfall['titel'])
        ->and($absage['text'])->not->toBe($ausfall['text']);
});

it('jeder der drei Saetze ist ueberschreibbar, mit einer Vorgabe', function () {
    // Ein Paket, das Saetze ausliefert, liefert eine Sprache aus (Wiki #595).
    // Deshalb eine Vorgabe und keine feste Verdrahtung.
    config()->set('calendar.brain_private_events.hinweise.nicht_erlaubt.titel', 'Eigener Titel');
    config()->set('calendar.brain_private_events.hinweise.nicht_erlaubt.text', 'Eigener Text');

    $hinweis = quelleMitZustand(Freigabezustand::NichtErlaubt)->abwesenheitsHinweis();

    expect($hinweis['titel'])->toBe('Eigener Titel')
        ->and($hinweis['text'])->toBe('Eigener Text');
});

/*
|--------------------------------------------------------------------------
| Der Waechter zum optionalen Vertrag (Wiki #595)
|--------------------------------------------------------------------------
*/

it('WAECHTER: jede Quelle, die den Zustand kennen KANN, meldet ihn auch', function () {
    /*
     * „Ein optionaler Vertrag bricht nichts, er FEHLT nur" — und niemand
     * merkt es, weil die Maske aufgeht und der Hinweis ausbleibt. Genau so
     * blieb `BrainWerkzeugquelle` ohne den Suchvertrag, und die Trefferliste
     * blieb leer, ohne dass etwas rot wurde.
     *
     * Geprueft wird deshalb die KOPPLUNG: Wer `MeldetAusfall` umsetzt, kennt
     * den Zustand der Gegenstelle — und muss dann auch `MeldetAbsage`
     * umsetzen. Sonst faellt eine Absage bei ihm stumm in den Ausfall oder
     * verschwindet.
     *
     * WAS DIESER WAECHTER NICHT SIEHT, ausdruecklich: Er laeuft ueber die
     * Klassen DIESES Pakets. Eine Quelle, die ein PRODUKT anmeldet, prueft er
     * nicht — dafuer muesste er die Registry zur Laufzeit lesen, und die ist
     * je Produkt anders gefuellt. Er ist damit eine Zusicherung ueber das
     * Paket, nicht ueber den Verbund.
     */
    $dateien = glob(__DIR__.'/../../src/Sources/*.php') ?: [];

    // Gegenprobe nach oben: Findet die Suche ueberhaupt etwas? Eine leere
    // Menge waere gruen und wertlos.
    expect(count($dateien))->toBeGreaterThan(5);

    $melderDesAusfalls = [];

    foreach ($dateien as $datei) {
        $klasse = 'Peppermint\\Calendar\\Sources\\'.basename($datei, '.php');

        if (! class_exists($klasse)) {
            continue;
        }

        if (is_a($klasse, MeldetAusfall::class, true)) {
            $melderDesAusfalls[] = $klasse;
        }
    }

    // Gegenprobe nach oben, zweite Stufe: Es MUSS mindestens eine geben,
    // sonst prueft die Schleife darunter nichts.
    expect($melderDesAusfalls)->not->toBeEmpty();

    foreach ($melderDesAusfalls as $klasse) {
        expect(is_a($klasse, MeldetAbsage::class, true))->toBeTrue(
            $klasse.' meldet einen Ausfall, aber keine Absage — dann faellt eine '
            .'Absage dort stumm in den Ausfall. Siehe MeldetAbsage.',
        );
    }
});
