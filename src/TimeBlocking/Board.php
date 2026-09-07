<?php

namespace Peppermint\Calendar\TimeBlocking;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Scheduling\PlannedSubjects;

/**
 * The planning board: what is still waiting to be scheduled, what is already
 * in the day, and where the day collides with itself.
 *
 * This is the part that must not be written twice. The view differs between
 * frameworks; the rule for when an item leaves the list does not — and two
 * implementations of that rule will disagree eventually, in ways nobody
 * notices until a task quietly stops appearing.
 */
class Board
{
    public function __construct(
        protected PlannedSubjects $planned,
    ) {}

    /**
     * @param  iterable<PlannableItem>  $items  what the application offers for planning
     * @return array{window: array{from: string, to: string}, open: array<int, array<string, mixed>>, scheduled: array<int, array<string, mixed>>, events: array<int, array<string, mixed>>, collisions: array<int, array<int, int>>, preferences: array<string, mixed>}
     */
    public function build(
        int $userId,
        string $subjectType,
        iterable $items,
        CarbonInterface $from,
        CarbonInterface $to,
        ?BoardPreferences $preferences = null,
    ): array {
        $preferences ??= new BoardPreferences;
        $from = CarbonImmutable::parse($from);
        $to = CarbonImmutable::parse($to);

        $everPlanned = $this->planned->ever($userId, $subjectType, $preferences->kinds);
        $plannedInWindow = $this->planned->within($userId, $subjectType, $from, $to, $preferences->kinds);

        $open = [];
        $scheduled = [];

        foreach ($items as $item) {
            // A one-off item is done with the list as soon as it has any block
            // at all. A recurring one comes back for every window it is not yet
            // planned in — otherwise it would leave the list after being
            // scheduled once and never return.
            $isPlanned = $item->recurring
                ? in_array($item->id, $plannedInWindow, true)
                : in_array($item->id, $everPlanned, true);

            $isPlanned
                ? $scheduled[] = $item->toArray()
                : $open[] = $item->toArray();
        }

        $events = $this->events($userId, $from, $to, $preferences);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'open' => $open,
            'scheduled' => $scheduled,
            'events' => $events->map(fn (CalendarEvent $event) => [
                'id' => $event->id,
                'kind' => $event->field('kind'),
                'title' => $event->title,
                'starts_at' => $event->field('starts_at')?->toIso8601String(),
                'ends_at' => $event->field('ends_at')?->toIso8601String(),
                'all_day' => (bool) $event->field('all_day'),
                'subject_type' => $event->field('subject_type'),
                'subject_id' => $event->field('subject_id'),
            ])->values()->all(),
            'collisions' => $this->collisions($events),
            'preferences' => $preferences->toArray(),
        ];
    }

    /** @return Collection<int, CalendarEvent> */
    protected function events(int $userId, CarbonImmutable $from, CarbonImmutable $to, BoardPreferences $preferences): Collection
    {
        $query = CalendarEvent::query()->inRange($from, $to)->orderBy(CalendarEvent::column('starts_at'));

        $preferences->onlyOwnBlocks
            ? $query->where(CalendarEvent::column('owner_id'), $userId)
            : $query->visibleTo($userId);

        if ($preferences->kinds !== []) {
            $query->ofKind(...$preferences->kinds);
        }

        if (! $preferences->showAllDayEvents) {
            $query->where(CalendarEvent::column('all_day'), false);
        }

        $events = $query->get();

        if (! $preferences->showWeekends) {
            // Filtered in PHP, not in SQL: every database spells weekday
            // extraction differently, and a driver-specific expression here
            // would pass the test suite on SQLite and fail on MySQL.
            $events = $events->reject(fn (CalendarEvent $event) => $event->field('starts_at')->isWeekend())->values();
        }

        return $events;
    }

    /**
     * Pairs of events occupying the same time. Reported rather than prevented:
     * a planner may well want two things in one slot, it just must not happen
     * without the person noticing.
     *
     * @param  Collection<int, CalendarEvent>  $events
     * @return array<int, array<int, int>>
     */
    protected function collisions(Collection $events): array
    {
        $pairs = [];
        $list = $events->values();

        foreach ($list as $i => $event) {
            foreach ($list->slice($i + 1) as $other) {
                if ($event->field('starts_at') < $other->field('ends_at')
                    && $event->field('ends_at') > $other->field('starts_at')) {
                    $pairs[] = [$event->id, $other->id];
                }
            }
        }

        return $pairs;
    }
}
