<?php

namespace Peppermint\Calendar\Kinds;

use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Eine Terminart. Die einbindende Anwendung leitet je Art eine Klasse ab und
 * registriert sie — das Paket bringt keine Arten mit.
 *
 * Die Art beantwortet drei Fragen, die der Kern nicht beantworten kann:
 * wie heisst sie, welche Zusatzfelder hat sie (Profil), und was passiert
 * beim Löschen.
 */
abstract class EventKind
{
    /** Schlüssel, wie er in `calendar_events.kind` steht. Stabil halten — er steht in den Daten. */
    abstract public function key(): string;

    /** Bezeichnung für die Oberfläche. */
    abstract public function label(): string;

    /**
     * Profil-Modell mit den Feldern dieser Art (1:1 zum Termin), oder null,
     * wenn die Art ohne Zusatzfelder auskommt.
     *
     * @return class-string|null
     */
    public function profileModel(): ?string
    {
        return null;
    }

    /**
     * Führt diese Art einen Papierkorb?
     *
     * false bedeutet: Löschen entfernt den Termin sofort aus der Datenbank,
     * samt Profil und Teilnehmern. Für private Termine ist das die richtige
     * Antwort — wer seinen eigenen Termin löscht, erwartet nicht, dass er
     * irgendwo weiterlebt.
     */
    public function usesTrash(): bool
    {
        return true;
    }

    /**
     * Tage, die ein gelöschter Termin im Papierkorb bleibt, bevor er
     * endgültig entfernt wird. null = unbegrenzt. Ohne Papierkorb bedeutungslos.
     */
    public function trashRetentionDays(): ?int
    {
        return config('calendar.trash_retention_days');
    }

    /**
     * Validierungsregeln für die Zusatzfelder dieser Art. Der Kern prüft sie
     * nicht selbst — die Anwendung zieht sie in ihre Requests.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Felder, die bei dieser Art NICHT gesetzt sein dürfen. Der Riegel gegen
     * die Flagge, die zur Attrappe wird: ein privater Termin mit Projekt und
     * Abrechnung ist kein privater Termin mehr.
     *
     * @return array<int, string>
     */
    public function forbiddenAttributes(): array
    {
        return [];
    }

    /** Haken für die Anwendung, bevor ein Termin dieser Art gespeichert wird. */
    public function saving(CalendarEvent $event): void {}
}
