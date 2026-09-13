<?php

namespace Peppermint\Calendar\Kinds;

use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Sources\ExternalKind;

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

    /**
     * Does this kind keep a trash bin?
     *
     * false means deleting removes the event from the database immediately,
     * profile and attendees included. For private appointments that is the
     * right answer — someone deleting their own appointment does not expect it
     * to live on somewhere.
     */
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
     * Does this kind carry categories at all?
     *
     * Categories are an extra. A time block on a task does not need one, and
     * offering an empty select next to it is worse than offering nothing.
     */
    public function usesCategories(): bool
    {
        return false;
    }

    /**
     * Free-text labels for this event's `CATEGORIES` line, in the order they
     * should appear. Empty means the line is left out entirely.
     *
     * The kind answers this because only it knows where its categories live —
     * in its profile, in an adopted column, or nowhere. Strings, not finished
     * iCalendar: escaping, separators and folding stay in the package, or they
     * end up built a second time and wrong.
     *
     * @return array<int, string>
     */
    public function categories(CalendarEvent $event): array
    {
        return [];
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

    /**
     * Fields this kind cannot be saved without, read off its own `rules()`.
     *
     * Derived rather than declared a second time: a kind that states `required`
     * in its rules and then repeats the same list for the form has two places
     * to forget, and they drift apart quietly.
     *
     * @return array<int, string>
     */
    public function requiredAttributes(): array
    {
        $required = [];

        foreach ($this->rules() as $field => $rule) {
            $tokens = match (true) {
                is_string($rule) => explode('|', $rule),
                is_array($rule) => $rule,
                default => [],
            };

            foreach ($tokens as $token) {
                if ($token === 'required') {
                    $required[] = (string) $field;

                    continue 2;
                }
            }
        }

        return $required;
    }

    /**
     * The kind as a user interface needs it.
     *
     * Deliberately the same keys as {@see ExternalKind::toArray()}:
     * a dialogue that has to read its own kinds differently from another
     * system's kinds is two dialogues, and the point of this package is that it
     * is one.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'requires' => $this->requiredAttributes(),
            'forbids' => array_values($this->forbiddenAttributes()),
            'usesCategories' => $this->usesCategories(),
            'creatable' => $this->isUserCreatable(),
        ];
    }

    /** Hook for the application before an event of this kind is saved. */
    public function saving(CalendarEvent $event): void {}
}
