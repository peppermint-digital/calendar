<?php

namespace Peppermint\Calendar\Kinds;

use Peppermint\Calendar\Models\CalendarEvent;

/**
 * An event kind. The consuming application subclasses this once per kind and
 * registers it — the package ships none of its own.
 *
 * A kind answers three questions the core cannot: what it is called, which
 * extra fields it carries (its profile), and what happens when it is deleted.
 */
abstract class EventKind
{
    /** Key as stored in `calendar_events.kind`. Keep it stable — it lives in the data. */
    abstract public function key(): string;

    /** Label for the user interface. */
    abstract public function label(): string;

    /**
     * Profile model holding this kind's fields (1:1 with the event), or null
     * when the kind needs no extra fields.
     *
     * @return class-string|null
     */
    public function profileModel(): ?string
    {
        return null;
    }

    /**
     * Does this kind keep a trash bin?
     *
     * false means deleting removes the event from the database immediately,
     * profile and attendees included. For private appointments that is the
     * right answer — someone deleting their own appointment does not expect it
     * to live on somewhere.
     */
    /**
     * May a person pick this kind when creating an event by hand?
     *
     * Not every kind is something you *choose*. A planned block appears by
     * dragging a task into the day; a leave entry mirrors an approved request.
     * Offering those in a "what would you like to create?" dialogue asks a
     * question that has no useful answer — and an entry created that way would
     * be missing whatever it exists to mirror.
     *
     * Kinds that only ever come into being through another action say so by
     * returning false. The default is true: a kind is normally something you
     * can create.
     */
    public function isUserCreatable(): bool
    {
        return true;
    }

    public function usesTrash(): bool
    {
        return true;
    }

    /**
     * Days a deleted event stays recoverable before it is purged.
     * null = forever. Meaningless for kinds without a trash bin.
     */
    public function trashRetentionDays(): ?int
    {
        return config('calendar.trash_retention_days');
    }

    /**
     * Validation rules for this kind's extra fields. The core does not apply
     * them itself — the application pulls them into its own requests.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Core fields this kind must never set. The guard against a kind decaying
     * into a meaningless flag: a private appointment carrying a project and a
     * billing target is not a private appointment any more.
     *
     * @return array<int, string>
     */
    public function forbiddenAttributes(): array
    {
        return [];
    }

    /** Hook for the application before an event of this kind is saved. */
    public function saving(CalendarEvent $event): void {}
}
