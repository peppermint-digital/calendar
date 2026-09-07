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
     * @param  string|null  $subjectType  what this is — 'task', 'habit', 'training_unit'. Null means
     *                                    "whatever the board was called with"; a board that offers
     *                                    several sorts at once needs it, because subject ids are only
     *                                    unique per type: task 5 and habit 5 are different things.
     * @param  array<string, mixed>  $meta  anything the application's UI needs (project, due date, effort …)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly bool $recurring = false,
        public readonly array $meta = [],
        public readonly ?string $subjectType = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            title: (string) $data['title'],
            recurring: (bool) ($data['recurring'] ?? false),
            meta: $data['meta'] ?? [],
            subjectType: $data['subject_type'] ?? null,
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
            'subject_type' => $this->subjectType,
        ];
    }
}
