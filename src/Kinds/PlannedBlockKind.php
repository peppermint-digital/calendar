<?php

namespace Peppermint\Calendar\Kinds;

use Illuminate\Support\Str;

/**
 * Ein Gegenstand, der in den Kalender geplant ist.
 *
 * Eine Aufgabe, ein Ticket, ein Deal: Der Termin ist nicht die Sache selbst,
 * sondern ihre Planung. Diese Form kam in drei Systemen unabhaengig voneinander
 * vor und war dabei ZWEIMAL verschieden gebaut:
 *
 *     Manager       manager_task        Profil mit echtem `task_id`
 *     AI Brain      brain_task          Profil mit echtem `task_id`
 *     Verwaltung    verwaltung_ticket   KEIN Profil, nur `subject_type`/`subject_id`
 *
 * Zwei Antworten auf dieselbe Frage. Dadurch war nichts davon teilbar: keine
 * gemeinsame Abfrage, keine gemeinsame Konfliktregel, keine gemeinsame
 * Darstellung — jedes System hat seinen Zeitblock von Hand nachgebaut, samt
 * der drei Entscheidungen, die man dabei falsch treffen kann.
 *
 * Seit dem 14.10.2026 benutzen alle drei diese Klasse; die Verwaltung hat ihre
 * Profiltabelle nachgezogen. Die Tabelle oben steht noch hier, weil sie die
 * Frage beantwortet, warum es diese Klasse gibt.
 *
 * ## Was die Art NICHT ausliefern kann
 *
 * Sich selbst. Der Gegenstand ist je System ein anderer, und ein
 * Fremdschluessel kann nur auf eine Tabelle zeigen, die es gibt. Ausgeliefert
 * wird die FORM — die drei Entscheidungen und die Verdrahtung des Profils:
 *
 *     class TicketZeitblockArt extends PlannedBlockKind
 *     {
 *         public function key(): string          { return 'verwaltung_ticket'; }
 *         public function label(): string        { return 'Ticket-Zeitblock'; }
 *         public function subjectModel(): string { return Ticket::class; }
 *     }
 *
 * ## Warum die drei Entscheidungen so und nicht anders fallen
 *
 * **Nicht von Hand anlegbar.** Ein Zeitblock entsteht, indem man einen
 * Gegenstand in den Tag zieht. Ihn in einem „Was moechtest du anlegen?"-Dialog
 * anzubieten, stellt eine Frage ohne nuetzliche Antwort — und ein so angelegter
 * Block haette nichts, was er planen soll.
 *
 * **Kein Papierkorb.** Der Block ist die Abbildung einer Planung. Verschwindet
 * der Gegenstand, hat er keinen Sinn mehr; ihn dreissig Tage nachziehen zu
 * lassen heisst, Zeit als verplant auszuweisen, die frei ist.
 *
 * **Der Fremdschluessel steht im Profil, nicht im Kern.** `subject_id` im Kern
 * ist eine lose Zahl und muss es bleiben — der Kern kann keine Tabelle
 * referenzieren, die er nicht kennt. Im Profil kann er es: Wird die Aufgabe
 * geloescht, geht ihre Planung mit, statt als Termin ohne Gegenstand
 * zurueckzubleiben.
 */
abstract class PlannedBlockKind extends EventKind
{
    /**
     * Das Modell des Gegenstands, der geplant wird.
     *
     * @return class-string
     */
    abstract public function subjectModel(): string;

    /**
     * Die Spalte im Profil, die auf ihn zeigt.
     *
     * Abgeleitet aus dem Modellnamen (`Task` → `task_id`), weil das in allen
     * vorhandenen Faellen stimmt und ein eigener Name eine Entscheidung mehr
     * waere. Ueberschreibbar, wenn der abgeleitete Name stottert oder zu lang
     * wird — siehe `CalendarSchema::plannedBlockProfile()`.
     */
    public function subjectKey(): string
    {
        return Str::snake(class_basename($this->subjectModel())).'_id';
    }

    /**
     * Weitere Profilfelder dieser Art, ueber den Gegenstand hinaus.
     *
     * Der Manager traegt am Zeitblock zusaetzlich `project_id`. Gemessen ist es
     * dort bei 307 von 309 Bloecken dasselbe Projekt wie an der Aufgabe — also
     * ableitbar, aber nicht bei den zwei Bloecken ohne Aufgabe.
     *
     * @return array<int, string>
     */
    public function extraProfileFields(): array
    {
        return [];
    }

    final public function profileFields(): array
    {
        return array_merge([$this->subjectKey()], $this->extraProfileFields());
    }

    public function isUserCreatable(): bool
    {
        return false;
    }

    public function usesTrash(): bool
    {
        return false;
    }
}
