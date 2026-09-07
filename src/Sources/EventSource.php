<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonInterface;

/**
 * A calendar of another system, shown alongside the local one.
 *
 * The distinction to an event kind matters: a kind describes events THIS
 * application owns and can change. A source describes events that belong
 * elsewhere and are only displayed. Neither system is the centre — each one
 * registers the sources it wants to see, and the same package serves both
 * directions.
 */
abstract class EventSource
{
    /** Stable key. It prefixes the ids this source returns, so keep it. */
    abstract public function key(): string;

    /** Label for the user interface. */
    abstract public function label(): string;

    /**
     * Events of this source for one person and one window.
     *
     * @return array<int, ExternalEvent>
     */
    abstract public function events(int $userId, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Is the source usable at all? A source that is not configured is skipped
     * silently — an application should not have to remove it from the list just
     * because credentials are missing on one installation.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /** Colour hint for the interface; the application may ignore it. */
    public function colour(): ?string
    {
        return null;
    }
}
