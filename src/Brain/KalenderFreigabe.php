<?php

namespace Peppermint\Calendar\Brain;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
use Throwable;

/**
 * Hat die angemeldete Person ihren Kalender in der Nabe freigeschaltet?
 *
 * ## Woher die Frage kommt
 *
 * Die Kalender-Werkzeuge der Nabe hängen an einer persönlichen Freischaltung
 * (`hasPluginCapability('calendar')`). Wer sie nicht hat, bekommt sie gar nicht
 * angemeldet — und der Abruf endete in einer `WARNING` je Seitenaufruf.
 *
 * Gemessen am 27.10.2026 im Peppermint Manager: **144 solche Zeilen an einem
 * Tag**, von vier Personen (34/26/9/4), Verteilung nach Arbeitstag mit Spitze
 * 52 um 12 Uhr. Kein Zeitplan, sondern Menschen, die auf ihren Kalender
 * schauen. Keine dieser Zeilen war ein Fehler.
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
 * kostet, hätte die 144 nur umbenannt — darum geht es hier überhaupt.
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

    public function fuerAngemeldetePerson(): bool
    {
        $person = $this->personId();

        // Keine angemeldete Person, keine persönliche Freischaltung. Ein
        // Hintergrundlauf soll diese Quelle nicht im Namen irgendwessen
        // öffnen — und die Nabe wiese den Vorgang ohnehin ab.
        if ($person === null) {
            return false;
        }

        $schluessel = 'calendar.brain_freigabe:'.$person;
        $gemerkt = Cache::get($schluessel);

        if ($gemerkt !== null) {
            return (bool) $gemerkt;
        }

        try {
            $frei = in_array(self::WERKZEUG, $this->werkzeugnamen(), true);
        } catch (BrainBridgeMissing $fehlt) {
            // Die Brücke fehlt im Produkt. Das ist keine Frage der Person und
            // auch kein Aussetzer — es bleibt so, bis jemand sie installiert.
            // Deshalb WIRD das gemerkt: Sonst klopft jeder Seitenaufruf an
            // eine Tür, die es nicht gibt.
            Cache::put($schluessel, false, self::SEKUNDEN);

            return false;
        } catch (Throwable) {
            // Aussetzer der Nabe — nicht merken, siehe Klassenkopf.
            return false;
        }

        Cache::put($schluessel, $frei, self::SEKUNDEN);

        return $frei;
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
