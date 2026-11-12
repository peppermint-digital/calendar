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
     *
     * Seit dem 12.11.2026 heisst das enger als vorher: NUR noch „es kam keine
     * Antwort". Eine Antwort, die eine ABSAGE ist, steht in
     * {@see NichtErlaubt} — siehe dort, warum das zwei Dinge sind.
     */
    case NichtErreichbar = 'nicht_erreichbar';

    /**
     * Die Nabe hat GEANTWORTET — und abgelehnt.
     *
     * Hinzugekommen am 12.11.2026 (Aufgabe #9882). Davor fiel dieser Fall mit
     * {@see NichtErreichbar} zusammen, weil `KalenderFreigabe` jeden
     * `Throwable` gleich behandelte. Die Folge war der Satz, den Bastian in
     * der Anlege-Maske des Managers gelesen hat:
     *
     *   „Nicht erreichbar: AI Brain (privat)."
     *
     * Die Nabe war aber erreichbar. Sie hat mit HTTP 401 geantwortet — die
     * Reproduktionsschritte von Bug #1417 sagen es selbst: „leere
     * OAuth-Zugangsdaten reichen, Aufruf endet in HTTP 401". Das ist eine
     * Absage und keine Stoerung, und es sind zwei verschiedene naechste
     * Schritte: Eine Stoerung geht vorueber, eine Absage behebt ein Mensch.
     *
     * ## Warum das kein fuenfter „verschwinden"-Fall ist
     *
     * Dieser Zustand MELDET sich, wie {@see NichtErreichbar} — nur mit einem
     * anderen Satz. Er gehoert damit auf die Seite „ich konnte nicht
     * nachsehen" und nicht auf die Seite „niemand ist zustaendig"
     * (Wiki #595). Wer ihn verschwinden liesse, waere zurueck bei #1417.
     *
     * ## Die vier Fehlerarten des Komm-Layers, hier angekommen
     *
     * Abgebildet wird in {@see \Peppermint\Calendar\Brain\KalenderFreigabe}:
     *
     * | Komm-Layer | hier |
     * |---|---|
     * | `not_allowed` (401/403) | **dieser Fall** |
     * | `not_offered` | {@see NichtFreigeschaltet} — die Nabe hat geantwortet, das Werkzeug stand nicht in der Liste |
     * | `unreachable` | {@see NichtErreichbar} |
     * | `tool_failed` | {@see NichtErreichbar} — bewusst, siehe dort |
     */
    case NichtErlaubt = 'nicht_erlaubt';

    /** Ist die Quelle in diesem Zustand benutzbar? */
    public function istFrei(): bool
    {
        return $this === self::Frei;
    }

    /**
     * Darf diese Antwort gemerkt werden?
     *
     * Alles ausser dem Ausfall und der Absage. `KeinePerson` entsteht ohne
     * Netzweg und braucht es nicht — gemerkt wird, was einen Aufruf gekostet
     * hat.
     *
     * ## Warum die ABSAGE nicht gemerkt wird, obwohl sie bleibt
     *
     * Entschieden am 12.11.2026, und zwar gegen das naheliegende Gegenteil.
     * Fuer das Merken sprach die Begruendung, mit der {@see BrueckeFehlt}
     * gemerkt WIRD: Eine Absage bleibt, bis jemand sie behebt, und ungemerkt
     * klopft jeder Seitenaufruf an eine Tuer, die gerade „nein" sagt — genau
     * die Last, gegen die dieser Zwischenspeicher ueberhaupt gebaut wurde
     * (73 Vorfaelle an zwei Tagen, im FEHLERlog).
     *
     * Dagegen sprach der SCHADEN, und er wiegt mehr: Eine falsch gemerkte
     * Absage behauptet 60 Sekunden lang etwas ueber BERECHTIGUNGEN. Das
     * schickt die Person zu einer Administratorin, die nichts zu tun hat —
     * und genau diese Fehlleitung hat
     * {@see \Peppermint\Calendar\Sources\BrainPrivateEventSource::abwesenheitsHinweis()}
     * bei #1417 gerade abgeschafft. Sie auf einem zweiten Weg
     * wiedereinzufuehren waere derselbe Fehler, nur teurer, weil er diesmal
     * aus dem Zwischenspeicher kommt und nicht aus einem Satz.
     *
     * Und der Preis ist vertretbar, weil der Klassenkopf von
     * {@see \Peppermint\Calendar\Brain\KalenderFreigabe} ihn fuer genau
     * diese Lage schon einmal akzeptiert hat: „Der Preis ist ein Abruf je
     * Seitenaufruf, solange die Nabe weg ist — immer noch die Haelfte von
     * vorher, und es ist ein Ausfall und kein Normalbetrieb." Eine Absage ist
     * ebenso ein Fehlzustand und kein Normalbetrieb.
     */
    public function merkbar(): bool
    {
        return $this !== self::NichtErreichbar
            && $this !== self::NichtErlaubt
            && $this !== self::KeinePerson;
    }
}
