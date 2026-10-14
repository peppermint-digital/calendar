<?php

namespace Peppermint\Calendar\Kinds;

use Illuminate\Support\Str;
use Peppermint\Calendar\Exceptions\PlannedBlockWithoutSubject;
use Peppermint\Calendar\Models\CalendarEvent;

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

    /**
     * Was in `subject_type` steht.
     *
     * Abgeleitet aus dem Modellnamen, wie `subjectKey()`: `Task` → `task`,
     * `Ticket` → `ticket`, `TrainingUnit` → `training_unit`. Der Wert ist
     * absichtlich NICHT der Klassenname — das lose Paar lesen auch andere
     * Systeme, und die kennen unsere Namensraeume nicht.
     */
    public function subjectType(): string
    {
        return Str::snake(class_basename($this->subjectModel()));
    }

    /**
     * Fuellt das lose Paar aus dem Fremdschluessel — und laesst keinen Block
     * ohne Gegenstand durch.
     *
     * ## Warum das Paar ueberhaupt noch gefuellt wird
     *
     * `subject_type`/`subject_id` und der Fremdschluessel im Profil sagen
     * dasselbe und leisten Verschiedenes: Das Paar lesen die Planungsregeln
     * des Pakets, OHNE die Terminart zu kennen (`PlannedSubjects`,
     * `TimeBlocking\Board`) — der Fremdschluessel ist der Weg, auf den die
     * Datenbank aufpasst. So steht es in Entscheidung #3489.
     *
     * ## Warum das Paket es tut und nicht die Anwendung
     *
     * Gemessen am 14.10.2026 im Peppermint Manager: `subject_type` war bei
     * ALLEN 1207 Terminen leer, `subject_id` ebenso — die 484 Zeitbloecke
     * hingen nur am Fremdschluessel. Das Board haette dort nichts gefunden und
     * jede eingeplante Aufgabe wieder als „offen" gezeigt. Eine Regel, die
     * jedes System selbst einhalten muss, wird irgendwo nicht eingehalten;
     * eine, die die Form mitbringt, gilt ueberall.
     *
     * `final`, damit sie nicht aus Versehen weggeerbt wird — wer am Speichern
     * mitreden will, ueberschreibt {@see savingBlock()}.
     */
    final public function saving(CalendarEvent $event): void
    {
        $schluessel = $this->subjectKey();
        $gegenstand = $event->getAttribute($schluessel);

        if (blank($gegenstand)) {
            // Eine BESTEHENDE Zeile ohne Gegenstand geht durch, solange
            // niemand an ihr dreht. Derselbe Grund wie bei den verbotenen
            // Feldern: Anwendungen uebernehmen Arten fuer Daten, die aelter
            // sind als die Regel — und ein Riegel, der eine unangetastete
            // Altzeile ablehnt, macht aus „das darf nicht dazukommen" ein
            // „diese Zeile ist nie wieder speicherbar". Im Manager sind das
            // am 14.10.2026 drei von 484 Bloecken.
            if ($event->exists && ! $event->isDirty($schluessel)) {
                $this->savingBlock($event);

                return;
            }

            throw PlannedBlockWithoutSubject::make($this->key(), $schluessel);
        }

        $event->setAttribute(CalendarEvent::column('subject_type'), $this->subjectType());
        $event->setAttribute(CalendarEvent::column('subject_id'), $gegenstand);

        $this->savingBlock($event);
    }

    /** Der Haken fuer alles, was DIESE Art beim Speichern zusaetzlich tut. */
    public function savingBlock(CalendarEvent $event): void {}

    public function isUserCreatable(): bool
    {
        return false;
    }

    public function usesTrash(): bool
    {
        return false;
    }
}
