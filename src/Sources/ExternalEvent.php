<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonImmutable;

/**
 * An event that lives in another system.
 *
 * It is shown, not owned: no row in this database, no editing, no deleting.
 * Copying it here would create a second truth and a synchronisation problem
 * nobody asked for — the other system remains the place where it is changed.
 */
class ExternalEvent
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $id,
        public readonly string $title,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly bool $allDay = false,
        public readonly ?string $location = null,
        public readonly ?string $url = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            // Prefixed so it can never collide with a local event id — and so a
            // caller cannot accidentally pass it to something that writes.
            'id' => $this->sourceKey.':'.$this->id,
            'source' => $this->sourceKey,
            'external' => true,
            'title' => $this->title,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'all_day' => $this->allDay,
            'location' => $this->location,
            'url' => $this->url,
        ];
    }
}
