<?php

namespace Peppermint\Calendar\Brain;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Peppermint\Calendar\Enums\Freigabezustand;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
use Peppermint\Calendar\Exceptions\NabeLehntAb;
use Throwable;

/**
 * Hat die angemeldete Person ihren Kalender in der Nabe freigeschaltet?
 *
 * ## Woher die Frage kommt
 *
 * Die Kalender-Werkzeuge der Nabe hängen an einer persönlichen Freischaltung
 * (`hasPluginCapability('calendar')`). Wer sie nicht hat, bekommt sie gar nicht
 * angemeldet — und der Abruf endete je Seitenaufruf im Fehlerlog.
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
 * ## Gefragt wird die Werkzeugliste, nicht ein eigenes Werkzeug
 *
 * `tools/list` **ist** die Antwort: Die Nabe filtert sie je Identität, weil
 * `shouldRegister()` bei jedem Aufruf läuft. Was eine Person nicht
 * freigeschaltet hat, steht nicht in ihrer Liste.
 *
 * Ein eigenes „darf-ich"-Werkzeug wäre eine zweite Wahrheit über dieselbe
 * Frage und könnte von der ersten abweichen — und zwar lautlos, weil beide für
 * sich plausibel antworten.
 *
 * ## Der Zwischenspeicher, und warum er der eigentliche Zweck ist
 *
 * **60 Sekunden je Person.** Ein Riegel, der je Seitenaufruf einen Netzweg
 * kostet, hätte die 73 nur umbenannt — darum geht es hier überhaupt.
 *
 * 60 und nicht 300: Nach einer gerade erteilten Freischaltung soll die Quelle
 * bald erscheinen. Mit fünf Minuten sah die Person noch minutenlang „nicht
 * verbunden", obwohl sie es längst war, und wunderte sich zu Recht. Eine
 * Minute deckt eine Blätter-Sitzung im Kalender ab und ist kurz genug, dass
 * niemand etwas leeren muss.
 *
 * Ein **Fehlschlag wird NICHT gespeichert**. Sonst bliebe die Quelle nach
 * einem kurzen Aussetzer der Nabe noch eine Minute verborgen, obwohl sie
 * wieder da ist. Der Preis ist ein Abruf je Seitenaufruf, solange die Nabe
 * weg ist — immer noch die Hälfte von vorher, und es ist ein Ausfall und kein
 * Normalbetrieb.
 */
class KalenderFreigabe
{
    /**
     * Das Werkzeug, an dem sich die Freischaltung ablesen lässt.
     *
     * Dasselbe, das {@see \Peppermint\Calendar\Sources\BrainPrivateEventSource}
     * später wirklich aufruft. Die Prüfung fragt also nach genau dem, was
     * gebraucht wird, und nicht nach einem Stellvertreter.
     */
    public const WERKZEUG = 'list-calendar-bundle-tool';

    /** Wie lange die Antwort je Person gilt. */
    public const SEKUNDEN = 60;


    public function __construct(private readonly ?string $guard = null) {}

    /**
     * Der Riegel: Darf diese Person hier gerade?
     *
     * Bleibt ein `bool`, weil ein Riegel nur zwei Antworten braucht. Wer den
     * GRUND braucht — eine Oberflaeche, die einen Ausfall melden soll —, fragt
     * {@see zustand()}.
     */
    public function fuerAngemeldetePerson(): bool
    {
        return $this->zustand()->istFrei();
    }

    /**
     * Warum ist die Quelle da oder nicht da?
     *
     * ## Warum es diese Methode gibt
     *
     * Hier gab es bis zum 12.11.2026 nur `fuerAngemeldetePerson(): bool`, und
     * vier verschiedene Lagen endeten in demselben `false`. Fuer den Riegel
     * genuegte das; fuer die Maske nicht, und daran hing Bug #1417: Der
     * private Kalender der Nabe verschwand LAUTLOS aus der Anlege-Maske, wenn
     * die Nabe nicht erreichbar war — ununterscheidbar von „diese Person hat
     * ihren Kalender nicht freigeschaltet".
     *
     * Die Unterscheidung war an dieser Stelle VORHANDEN und wurde weggeworfen:
     * Der `Throwable` wurde abgefangen und gab dasselbe `false` wie eine
     * fehlende Freigabe. Weggeworfene Information wiederzubeschaffen ist
     * unmoeglich — deshalb steht die Behebung hier und nicht weiter oben.
     *
     * ## Der Zwischenspeicher merkt den Ausfall NICHT
     *
     * Das war vorher so und bleibt so, und es ist der Grund, warum der
     * Ausfall-Fall ueberhaupt gesondert gefuehrt wird: Ein einmaliger
     * Aussetzer darf nicht fuer die Dauer eines Caches zur Dauerantwort
     * werden. Die Quelle bliebe sonst noch eine Minute verborgen, obwohl sie
     * wieder da ist. Welche Antwort gemerkt werden darf, entscheidet der
     * Zustand selbst ({@see Freigabezustand::merkbar()}) und nicht ein
     * `if` an jeder Rueckgabestelle.
     *
     * ## Gelesen wird STRENG
     *
     * Im Speicher steht jetzt der Name des Zustands und nicht mehr ein Bool.
     * Gelesen wird er ueber `tryFrom()`, und was dabei nicht aufgeht, gilt als
     * NICHT gemerkt — dann wird neu gefragt. Der lose Weg waere
     * `(bool) $gemerkt`, und der liest ausgerechnet
     * `'nicht_freigeschaltet'` als `true`: Ein Wert aus einem aelteren Stand
     * im noch warmen Speicher wuerde die Quelle fuer jeden oeffnen. Dieselbe
     * Falle, vor der {@see \Peppermint\Calendar\Sources\RemoteResult} warnt.
     * Der Preis ist ein Netzweg, hoechstens einmal je Minute und Person.
     */
    public function zustand(): Freigabezustand
    {
        $person = $this->personId();

        // Keine angemeldete Person, keine persönliche Freischaltung. Ein
        // Hintergrundlauf soll diese Quelle nicht im Namen irgendwessen
        // öffnen — und die Nabe wiese den Vorgang ohnehin ab.
        if ($person === null) {
            return Freigabezustand::KeinePerson;
        }

        $schluessel = 'calendar.brain_freigabe:'.$person;
        $gemerkt = Freigabezustand::tryFrom((string) Cache::get($schluessel));

        if ($gemerkt !== null) {
            return $gemerkt;
        }

        $zustand = $this->erfragen();

        if ($zustand->merkbar()) {
            Cache::put($schluessel, $zustand->value, self::SEKUNDEN);
        }

        return $zustand;
    }

    /**
     * Die Nabe wirklich fragen — ohne Speicher, ohne Riegel.
     *
     * Getrennt von {@see zustand()}, damit der Speicher an EINER Stelle
     * entschieden wird. Vorher stand neben jedem `return` ein eigenes
     * `Cache::put` oder keines, und ob eines fehlte, war nur durch Zaehlen zu
     * sehen.
     */
    private function erfragen(): Freigabezustand
    {
        try {
            return in_array(self::WERKZEUG, $this->werkzeugnamen(), true)
                ? Freigabezustand::Frei
                : Freigabezustand::NichtFreigeschaltet;
        } catch (BrainBridgeMissing) {
            // Die Brücke fehlt im Produkt. Das ist keine Frage der Person und
            // auch kein Aussetzer — es bleibt so, bis jemand sie installiert.
            // Deshalb DARF das gemerkt werden: Sonst klopft jeder
            // Seitenaufruf an eine Tür, die es nicht gibt.
            return Freigabezustand::BrueckeFehlt;
        } catch (NabeLehntAb) {
            // Die Nabe hat GEANTWORTET und abgelehnt (HTTP 401/403).
            //
            // Dieser Zweig steht VOR dem `Throwable` darunter, und die
            // Reihenfolge ist die eigentliche Behebung: Bis zum 12.11.2026
            // gab es ihn nicht, also fiel eine Absage in den Ausfall-Zweig
            // und die Maske sagte „Nicht erreichbar: AI Brain (privat)" —
            // ueber ein System, das sehr wohl erreichbar war. Siehe
            // {@see \Peppermint\Calendar\Enums\Freigabezustand::NichtErlaubt}.
            return Freigabezustand::NichtErlaubt;
        } catch (Throwable) {
            // Aussetzer der Nabe. Meldet sich, und wird NICHT gemerkt, siehe
            // Klassenkopf.
            //
            // Hier landet seit dem 12.11.2026 ausdruecklich AUCH der
            // `tool_failed`-Fall (die Nabe antwortet, aber mit 5xx oder einem
            // JSON-RPC-Fehler). Das ist Absicht und kein vergessener Zweig:
            // Fuer die Person ist es dieselbe Lage wie ein Ausfall — sie kann
            // nichts tun, es ist ein Fehlzustand, und er darf deshalb genauso
            // NICHT gemerkt werden. Ein eigener Zustand haette einen eigenen
            // Satz gebraucht, der nichts anderes sagen koennte.
            return Freigabezustand::NichtErreichbar;
        }
    }

    /**
     * Die Werkzeuge, die die Nabe DIESER Identität anbietet.
     *
     * @return array<int, string>
     */
    protected function werkzeugnamen(): array
    {
        $bruecke = '\\Peppermint\\AiBrainBridge\\Facades\\AiBrain';

        if (! class_exists($bruecke)) {
            throw BrainBridgeMissing::forPrivateEvents();
        }

        try {
            $werkzeuge = $bruecke::brain()->listTools();
        } catch (Throwable $fehler) {
            throw $this->uebersetzt($fehler);
        }

        return array_values(array_filter(array_map(
            static fn ($werkzeug) => is_array($werkzeug) ? ($werkzeug['name'] ?? null) : null,
            $werkzeuge,
        ), 'is_string'));
    }

    /**
     * Eine Ausnahme der Bruecke in die Sprache dieses Pakets bringen.
     *
     * ## Warum die Pruefung ueber einen NAMEN laeuft und nicht ueber `catch`
     *
     * Die Bruecke steht in `suggest`, nicht in `require` (Regel 7). Ein
     * `catch (McpAufrufGescheitert)` verlangte ein `use` auf eine Klasse, die
     * in einer eigenstaendigen Installation nicht existiert — und das ist
     * nicht bloss unsauber, sondern STILL: PHP betritt einen `catch` auf einen
     * fehlenden Typ einfach nie, und kein Test wird dabei rot. Dieselbe
     * Begruendung, aus der schon die Fassade oben als Zeichenkette geprueft
     * wird.
     *
     * `is_a()` mit einem Klassennamen als Zeichenkette ist dafuer das richtige
     * Werkzeug: Es gibt bei einer unbekannten Klasse `false` zurueck, statt zu
     * werfen. Fehlt die Bruecke, ist die Antwort also „keine Absage" — und das
     * ist die Wahrheit, denn ohne Bruecke kam man gar nicht bis hierher.
     *
     * ## Die Abbildung steht HIER und nicht in der Bruecke
     *
     * Die Bruecke traegt die Tatsache (den Status), nicht ihre Deutung. Dass
     * 401 und 403 fuer eine Oberflaeche dasselbe heissen, ist eine Aussage
     * ueber DIESE Oberflaeche — sie fragt `istAbsage()` und nicht nach Zahlen,
     * damit die Grenze zwischen den beiden an einer Stelle liegt und nicht
     * hier noch einmal.
     *
     * Alles andere reist UNVERAENDERT weiter. Das ist Absicht: Ein Ausfall
     * soll seinen eigenen Typ behalten, damit {@see erfragen()} ihn am
     * `Throwable` erkennt, und ein Fehler, den wir nicht deuten koennen, soll
     * nicht als etwas Gedeutetes erscheinen.
     */
    private function uebersetzt(Throwable $fehler): Throwable
    {
        if (! is_a($fehler, $this->brueckenFehlerKlasse())) {
            return $fehler;
        }

        // Nur lesen, wenn die Klasse das Versprechen auch haelt. Eine
        // Bruecke, die aelter ist als der 12.11.2026, wirft noch die
        // Oberklasse ohne `istAbsage()` — dann bleibt es ein Ausfall, also der
        // Stand von vorher. Das ist die vorsichtige Richtung: Eine Absage, die
        // als Ausfall erscheint, ist der bekannte Fehler; ein Ausfall, der als
        // Absage erscheint, waere ein neuer.
        if (! method_exists($fehler, 'istAbsage') || ! $fehler->istAbsage()) {
            return $fehler;
        }

        return new NabeLehntAb(
            is_int($fehler->httpStatus ?? null) ? $fehler->httpStatus : null,
            $fehler->getMessage(),
        );
    }

    /**
     * Die Ausnahme der Bruecke, die einen HTTP-Status typisiert traegt.
     *
     * Als Zeichenkette und nicht als `use`, weil die Bruecke in `suggest`
     * steht — die vollstaendige Begruendung steht an {@see uebersetzt()}.
     * `McpAufrufGescheitert` erbt von `AiBrainBridgeException`; beide Namen
     * stehen hier, damit eine Suche nach einem von ihnen diese Stelle findet.
     *
     * Eine ueberschreibbare METHODE und keine Konstante, aus demselben Grund
     * wie bei {@see \Peppermint\Calendar\Sources\BrainPrivateEventSource::brueckeVorhanden()}:
     * In der Testreihe dieses Pakets ist die Bruecke nicht installiert, und
     * ohne eine ueberschreibbare Stelle waere die Uebersetzung nur in ihrer
     * NEGATIVEN Richtung pruefbar — `is_a()` gegen eine fehlende Klasse ist
     * immer `false`, der Zweig also nie betreten. Ein Verhalten, das kein
     * Test je betritt, ist kein gepruefte Verhalten.
     *
     * Dass der Prueflings-Ersatz von der echten Klasse abweichen koennte, ist
     * die bekannte Gefahr dabei. Sie ist hier klein gehalten, weil der Test
     * nur den NAMEN ersetzt und das Versprechen selbst — `istAbsage()` und
     * `httpStatus` — in der Bruecke eigene Tests hat (`McpFehlerartTest`).
     * Und die Leseseite hier haelt sich ausdruecklich zurueck: Ohne
     * `istAbsage()` bleibt es ein Ausfall, also der Stand von vorher.
     */
    protected function brueckenFehlerKlasse(): string
    {
        return '\\Peppermint\\AiBrainBridge\\Exceptions\\McpAufrufGescheitert';
    }

    /**
     * Wer ist angemeldet? — Die EINE Stelle dafür.
     *
     * Öffentlich, weil {@see \Peppermint\Calendar\Sources\BrainPrivateEventSource}
     * dieselbe Frage braucht: Es vergleicht den angefragten `$userId` damit,
     * um keinen fremden Privatkalender zu öffnen. Stand sie zweimal da, liefe
     * sie auseinander — und zwar an einer Grenze, an der das weh tut.
     *
     * Aufgefallen beim Schreiben der Tests: Die Quelle hatte ihre eigene
     * Fassung, und der Hinweis „nicht verbunden" blieb deshalb aus, obwohl die
     * Prüfung längst eine Person kannte.
     *
     * Über die Wache und nicht über ein Produktmodell — das kennt dieses Paket
     * nicht. Der Parameter ist derselbe wie bei
     * {@see \Peppermint\Calendar\Sources\BrainCalendarSources}: Die Verwaltung
     * führt ihre Menschen auf einer anderen Wache als Manager und CRM, und das
     * war dort schon einmal der Unterschied zwischen einer Kopie und einem
     * Parameter.
     */
    public function personId(): int|string|null
    {
        $wache = $this->guard !== null ? Auth::guard($this->guard) : Auth::guard();

        return $wache->id();
    }
}
