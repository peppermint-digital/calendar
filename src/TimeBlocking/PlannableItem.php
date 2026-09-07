<?php

namespace Peppermint\Calendar\TimeBlocking;

/**
 * Something a person can drag into their day: a task, a habit, a training
 * session. The core does not know what it is — the application hands it over
 * in this shape.
 */
class PlannableItem
{
    /**
     * @param  array<string, mixed>  $meta  anything the application's UI needs (project, due date, effort …)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly bool $recurring = false,
        public readonly array $meta = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            title: (string) $data['title'],
            recurring: (bool) ($data['recurring'] ?? false),
            meta: $data['meta'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'recurring' => $this->recurring,
            'meta' => $this->meta,
        ];
    }
}
