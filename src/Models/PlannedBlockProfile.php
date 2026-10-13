<?php

namespace Peppermint\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Das Profil eines geplanten Blocks: Termin plus Gegenstand.
 *
 * Die Tabelle und das Modell des Gegenstands bleiben bei der Anwendung — ein
 * Eloquent-Modell braucht eine feste Tabelle, und die Tabelle gehoert dem
 * Produkt. Was hier liegt, ist die Verdrahtung:
 *
 *     class BrainTaskProfile extends PlannedBlockProfile
 *     {
 *         protected $table = 'calendar_event_profile_brain_task';
 *         protected string $subjectModel = Task::class;
 *         protected string $subjectKey = 'task_id';
 *     }
 *
 * Vorher waren das je System rund fuenfundzwanzig Zeilen, dreimal fast gleich.
 */
abstract class PlannedBlockProfile extends Model
{
    protected $guarded = [];

    /** @var class-string */
    protected string $subjectModel;

    protected string $subjectKey = 'subject_id';

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    /**
     * Der geplante Gegenstand — eine Aufgabe, ein Ticket, ein Deal.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo($this->subjectModel, $this->subjectKey);
    }
}
