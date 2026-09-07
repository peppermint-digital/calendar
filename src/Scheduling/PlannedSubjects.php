<?php

namespace Peppermint\Calendar\Scheduling;

use Carbon\CarbonInterface;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Answers "what has this person already planned?" for subjects the core does
 * not know — tasks, habits, training sessions.
 *
 * Two questions, because planners need both:
 *
 *   - ever()    a one-off task disappears from the list as soon as it has any
 *               block at all
 *   - within()  a recurring task disappears only while the visible window
 *               already holds a block for it — otherwise it would vanish after
 *               being planned once and never come back
 *
 * Both count per person: someone else planning the same task must not remove
 * it from your list.
 */
class PlannedSubjects
{
    /**
     * @param  array<int, string>  $kinds  restrict to these event kinds, empty = all
     * @return array<int, int>  subject ids
     */
    public function ever(int $userId, string $subjectType, array $kinds = []): array
    {
        return $this->query($userId, $subjectType, $kinds)->pluck(CalendarEvent::column('subject_id'))->all();
    }

    /**
     * @param  array<int, string>  $kinds
     * @return array<int, int>  subject ids
     */
    public function within(
        int $userId,
        string $subjectType,
        CarbonInterface $from,
        CarbonInterface $to,
        array $kinds = [],
    ): array {
        return $this->query($userId, $subjectType, $kinds)
            ->inRange($from, $to)
            ->pluck(CalendarEvent::column('subject_id'))
            ->all();
    }

    /**
     * @param  array<int, string>  $kinds
     */
    protected function query(int $userId, string $subjectType, array $kinds)
    {
        $query = CalendarEvent::query()
            ->where(CalendarEvent::column('owner_id'), $userId)
            ->where(CalendarEvent::column('subject_type'), $subjectType)
            ->whereNotNull(CalendarEvent::column('subject_id'))
            ->distinct();

        if ($kinds !== []) {
            $query->ofKind(...$kinds);
        }

        return $query;
    }
}
