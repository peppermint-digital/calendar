<?php

namespace Peppermint\Calendar\Enums;

/**
 * Warum ist der private Kalender der Nabe gerade nicht da?
 *
 * ## Woher die Frage kommt
 *
 * `KalenderFreigabe::fuerAngemeldetePerson()` beantwortete vier verschiedene
 * Lagen mit demselben `false`. Fuer einen Riegel genuegt das — fuer eine
 * Oberflaeche nicht, und daran hing Bug #1417: Die unerreichbare Nabe
 * verschwand LAUTLOS aus der Anlege-Maske, ununterscheidbar von „diese Person
 * hat ihren Kalender nicht freigeschaltet".
 *
 * Das sind zwei Aussagen, nicht eine:
 *
 *   - **„Niemand ist zustaendig"** — soll verschwinden, es ist in Ordnung so.
 *   - **„Ich konnte nicht nachsehen"** — soll sich melden (Wiki #595).
 *
 * Die Unterscheidung war an der Stelle VORHANDEN und wurde weggeworfen: Der
 * `Throwable` wurde abgefangen und gab dasselbe `false` wie eine fehlende
 * Freigabe. Genau darin liegt der Unterschied zu Bug #1416, wo derselbe Weg
 * nicht baubar war, weil `RemoteEventSource::isWritable()` die Erreichbarkeit
 * gar nicht kennt.
 *
 * ## Vier Faelle und nicht zwei
 *
 * Auch die drei „verschwinden"-Faelle werden getrennt gefuehrt, obwohl sie
 * sich heute gleich verhalten. Sie unterscheiden sich darin, ob sich an ihnen
 * etwas aendern KANN: Eine fehlende Bruecke bleibt, bis jemand sie
 * installiert; eine fehlende Freischaltung kann die Person selbst erteilen.
 * Wer sie zusammenwirft, baut denselben Fehler eine Ebene hoeher noch einmal.
 */
enum Freigabezustand: string
{
    /** Freigeschaltet — die Quelle ist da. */
    case Frei = 'frei';

    /**
     * Niemand ist angemeldet.
     *
     * Ein Hintergrundlauf soll diese Quelle nicht im Namen irgendwessen
     * oeffnen, und die Nabe wiese den Vorgang ohnehin ab. Kein Netzweg, keine
     * Auskunft noetig.
     */
    case KeinePerson = 'keine_person';

    /**
     * Die Person hat ihren Kalender nicht freigeschaltet.
     *
     * Die Nabe hat GEANTWORTET — das Werkzeug stand nur nicht in ihrer Liste.
     * Das ist eine Auskunft und kein Ausfall.
     */
    case NichtFreigeschaltet = 'nicht_freigeschaltet';

    /**
     * Die Bruecke ist im Produkt nicht installiert.
     *
     * Keine Frage der Person und kein Aussetzer. Es bleibt so, bis jemand sie
     * installiert — deshalb darf diese Antwort gemerkt werden.
     */
    case BrueckeFehlt = 'bruecke_fehlt';

    /**
     * Die Nabe hat nicht geantwortet.
     *
     * Der EINE Fall, der sich melden muss. Und der einzige, der NICHT gemerkt
     * werden darf: Ein Aussetzer, der fuer die Dauer eines Caches zur
     * Dauerantwort wird, haelt die Quelle verborgen, obwohl sie wieder da ist.
     */
    case NichtErreichbar = 'nicht_erreichbar';

    /** Ist die Quelle in diesem Zustand benutzbar? */
    public function istFrei(): bool
    {
        return $this === self::Frei;
    }

    /**
     * Darf diese Antwort gemerkt werden?
     *
     * Alles ausser dem Ausfall. `KeinePerson` entsteht ohne Netzweg und
     * braucht es nicht — gemerkt wird, was einen Aufruf gekostet hat.
     */
    public function merkbar(): bool
    {
        return $this !== self::NichtErreichbar && $this !== self::KeinePerson;
    }
}
