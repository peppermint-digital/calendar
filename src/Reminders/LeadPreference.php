<?php

namespace Peppermint\Calendar\Reminders;

use Illuminate\Database\Eloquent\Model;

/**
 * Was EIN Mensch fuer seinen Termin-Vorlauf eingestellt hat.
 *
 * Eine fehlende Zeile ist der Normalfall und heisst „Vorgabe des Produkts".
 * Eine vorhandene Zeile mit leerem `lead_minutes` heisst „keine Erinnerung" —
 * das ist eine Entscheidung und kein Fehlen. Welche der drei Ebenen am Ende
 * gilt, entscheidet nicht dieses Modell, sondern {@see ReminderLead}; ein
 * Modell, das die Konfiguration mitliest, waere an jeder Stelle anders zu
 * umgehen.
 *
 * @property int $user_id
 * @property int|null $lead_minutes
 */
class LeadPreference extends Model
{
    /**
     * Leer statt `['id']`: Ein nicht-leeres `$guarded` fragt die Tabelle nach
     * ihren Spalten und merkt sich die Antwort statisch je Modellklasse. Dieses
     * Modell kann zur Laufzeit auf eine andere Tabelle zeigen — dieselbe Falle,
     * die {@see \Peppermint\Calendar\Models\CalendarEventAttendee} beschreibt.
     */
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('calendar.tables.reminder_preferences', 'calendar_reminder_preferences');
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'lead_minutes' => 'integer',
        ];
    }
}
