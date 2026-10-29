<?php

namespace Peppermint\Calendar\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Welche eigenen Termine in der Agenda einer Person überhaupt in Frage kommen.
 *
 * ## Warum es diesen Vertrag gibt
 *
 * `visibleTo` heißt in diesem Paket „Eigentum ODER Teilnahme". Das ist die
 * richtige Vorgabe und für drei von vier Systemen die ganze Wahrheit.
 *
 * Der Peppermint Manager hat eine breitere: Termine ohne Eigner gelten für
 * alle, eine Vorgesetzte sieht die ihrer Leute, und ein Zeitblock folgt der
 * Sichtbarkeit seiner AUFGABE und nicht der des Termins. Gemessen am
 * 29.10.2026 stand diese Regel dort **zweimal** — 819 Zeichen, zeichengleich,
 * einmal im Kalender-Dienst und einmal in der Agenda.
 *
 * ## Warum nicht einfach `scopeVisibleTo` im Produkt verbreitern
 *
 * Weil daran auch das MCP-Werkzeug, der ICS-Feed und die Kategorien hängen.
 * Wer die Agenda breiter machen will, verschiebt dort Rechte mit — lautlos.
 * Die Agenda braucht ihre eigene Antwort, und dieser Vertrag ist die Stelle,
 * an der sie steht.
 *
 * ## Warum ein Vertrag und keine Unterklasse
 *
 * {@see AgendaAssembler} ist `final`, und das bleibt es: Wer die Zeilen-Form
 * überschreiben kann, kann die Liste im Frontend von ihrem Server entkoppeln —
 * genau die Übersetzung, die der Klassenkopf dort ausschließt. Überschreibbar
 * sein soll die FRAGE „wer darf was sehen", nicht die ANTWORT-Form.
 *
 * Ohne Bindung gilt die Vorgabe. Ein Produkt, das es breiter braucht, bindet
 * seine Fassung im Dienstanbieter — dieselbe Form wie `ErinnerungsVerweis` in
 * `peppermint/reminders`.
 */
interface AgendaSichtbarkeit
{
    /**
     * Die Abfrage, auf der die Agenda dieser Person aufbaut.
     *
     * Zeitfenster und Sortierung setzt der Zusammensteller danach — hier steht
     * ausschließlich, WER was sehen darf.
     *
     * @return Builder<\Peppermint\Calendar\Models\CalendarEvent>
     */
    public function fuer(int $userId): Builder;
}
