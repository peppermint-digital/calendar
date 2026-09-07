<?php

namespace Peppermint\Calendar\Scheduling;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Peppermint\Calendar\Models\CalendarEvent;

class ScheduleConflictFinder
{
    /**
     * Events of one person that collide with the given slot.
     *
     * Touching slots do not collide: a block from 10:00 to 11:00 and one from
     * 11:00 to 12:00 are back to back, not on top of each other. Getting this
     * wrong makes a planner refuse every second slot for no reason a user can see.
     *
     * @param  array<int, string>  $kinds  restrict to these event kinds, empty = all
     * @return Collection<int, CalendarEvent>
     */
    public function conflicts(
        int $userId,
        CarbonInterface $start,
        CarbonInterface $end,
        array $kinds = [],
        ?int $ignoreEventId = null,
    ): Collection {
        $query = CalendarEvent::query()
            ->where('owner_id', $userId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at');

        if ($kinds !== []) {
            $query->whereIn('kind', $kinds);
        }

        // Moving an event must not collide with the version of itself that is
        // still in the database.
        if ($ignoreEventId !== null) {
            $query->whereKeyNot($ignoreEventId);
        }

        return $query->get();
    }

    public function hasConflict(
        int $userId,
        CarbonInterface $start,
        CarbonInterface $end,
        array $kinds = [],
        ?int $ignoreEventId = null,
    ): bool {
        return $this->conflicts($userId, $start, $end, $kinds, $ignoreEventId)->isNotEmpty();
    }
}
