<?php

namespace Peppermint\Calendar\Brain;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Peppermint\Calendar\Enums\Freigabezustand;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
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
        } catch (Throwable) {
            // Aussetzer der Nabe. Der eine Fall, der sich melden muss — und
            // der NICHT gemerkt wird, siehe Klassenkopf.
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

        return array_values(array_filter(array_map(
            static fn ($werkzeug) => is_array($werkzeug) ? ($werkzeug['name'] ?? null) : null,
            $bruecke::brain()->listTools(),
        ), 'is_string'));
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
