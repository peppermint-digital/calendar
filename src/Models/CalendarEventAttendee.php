<?php

namespace Peppermint\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventAttendee extends Model
{
    protected $table = 'calendar_event_attendees';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('calendar.user_model'), 'user_id');
    }

    public function isExternal(): bool
    {
        return $this->user_id === null;
    }
}
