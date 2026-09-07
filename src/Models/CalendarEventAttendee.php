<?php

namespace Peppermint\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventAttendee extends Model
{
    public function getTable(): string
    {
        return config('calendar.tables.attendees', 'calendar_event_attendees');
    }

    /**
     * Empty rather than ['id']: a non-empty guard makes Eloquent ask the table
     * for its column list and cache that answer statically, per model class.
     * This model can be pointed at a different table at runtime, so a cached
     * column list from an earlier table would silently drop every attribute the
     * new one does not share. Mass assignment is the application's business —
     * it validates its own requests.
     */
    protected $guarded = [];

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
