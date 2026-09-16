<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonImmutable;

/**
 * An event that lives in another system.
 *
 * It is shown, not owned: no row in this database. Copying it here would
 * create a second truth and a synchronisation problem nobody asked for.
 *
 * It CAN be acted upon — created, moved and deleted — but never here: every
 * such action travels to the owning system through a
 * {@see WritableEventSource} and returns whatever that system made of it. The
 * one thing that stays over there entirely is the full edit: title, attendees,
 * recurrence. Those live in a form with fields this application does not know,
 * and rebuilding it here would mean maintaining it twice. {@see $url} is the
 * way in.
 */
class ExternalEvent
{
    /**
     * Keys the core itself writes. Anything a source puts under `extra` is
     * removed if it collides — otherwise a remote system could overwrite the
     * id or the times of its own event by naming a field cleverly.
     *
     * @var array<int, string>
     */
    protected const RESERVED_KEYS = [
        'id', 'source', 'external', 'title',
        'starts_at', 'ends_at', 'all_day', 'location', 'url', 'extra',
    ];

    /**
     * Fields only the other system knows: a project, a customer, a billing
     * flag, an attendee list. They are shown and nothing else — never stored,
     * never written back, never mapped onto core columns.
     *
     * This is the answer to the pull every shared calendar table feels: a
     * product needs one more field, and the cheapest place looks like a new
     * column in the middle. A column would land in every other product too.
     * This does not, because an external event is never persisted.
     *
     * @var array<string, mixed>
     */
    public readonly array $extra;

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $id,
        public readonly string $title,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly bool $allDay = false,
        public readonly ?string $location = null,
        public readonly ?string $url = null,
        array $extra = [],
    ) {
        $this->extra = array_diff_key($extra, array_flip(self::RESERVED_KEYS));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
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

        // Left out entirely when there is nothing to say: a key that is always
        // present but usually empty teaches readers to ignore it.
        if ($this->extra !== []) {
            $payload['extra'] = $this->extra;
        }

        return $payload;
    }
}
