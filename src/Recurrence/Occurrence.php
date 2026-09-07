<?php

namespace Peppermint\Calendar\Recurrence;

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * One appearance of an event in a window.
 *
 * A single event yields one; a series yields one per date the rule produces.
 * Occurrences are computed, not stored: a series is one row, and expanding it
 * into rows would mean every rule change has to rewrite them — plus a stored
 * occurrence and its rule can disagree, and then nobody knows which is right.
 */
class Occurrence
{
    public function __construct(
        public readonly CalendarEvent $event,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly bool $isFirst = true,
    ) {}

    /**
     * Identifies this appearance: the event plus its date.
     *
     * The interface needs it to tell two appearances of the same series apart;
     * writes still address the event itself.
     */
    public function key(): string
    {
        return $this->event->getKey().'@'.$this->startsAt->toDateString();
    }
}
