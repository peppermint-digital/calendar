<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Teilnehmermodell, das die Paketklasse NICHT erweitert.
 *
 * Genau so steht es im Manager: `App\Models\CalendarEventAttendee` ist ein
 * eigenes Modell auf derselben Tabelle, und `CalendarEvent::attendees()` ist
 * dort überschrieben, damit es benutzt wird.
 *
 * Das ist erlaubt — ein Produkt darf seine Beziehungen austauschen. Deshalb
 * darf Paketcode nicht auf die eigene Klasse typisieren. Dass er es tat, hat
 * am 19.10.2026 die Einzelansicht des Managers mit einem TypeError zerlegt,
 * und zwar NUR bei Terminen mit Teilnehmern (#8307).
 */
class FremderTeilnehmer extends Model
{
    protected $table = 'calendar_event_attendees';

    public $timestamps = false;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(DemoUser::class, 'user_id');
    }
}
