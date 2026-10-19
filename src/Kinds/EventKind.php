<?php

namespace Peppermint\Calendar\Kinds;

use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Models\CalendarCategory;
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
     * Categories are an extra, and the kind decides — the default is no.
     *
     * The original wording held: "a time block on a task does not need one,
     * and offering an empty select next to it is worse than offering
     * nothing." On 19.10.2026 I overrode the first half, reasoning that 483
     * of 1211 Manager events are task blocks and all of that time lands under
     * "no category" in the report. That was the wrong way round, and the user
     * said so in one sentence: a block already points AT something.
     *
     * The subject carries the purpose, and more precisely than a six-item
     * list ever could — "task #4711 in project X for customer Y" against
     * "project work". Putting a coarser label next to a precise one adds
     * nothing. And nobody drags 483 blocks into a calendar and then clicks a
     * category onto each: the gap in the report would have stayed exactly
     * where it was, with more code around it.
     *
     * The lesson is about the direction of the argument. I started from the
     * empty row in the report instead of from what the person using it gains.
     * A hole in a report is a reason to ask what should fill it — not a
     * licence to invent a field.
     *
     * Where block time belongs in the report is through its SUBJECT, which
     * every block has by construction ({@see PlannedBlockKind}) — derived from
     * real data and nothing to click.
     */
    public function usesCategories(): bool
    {
        return false;
    }

    /**
     * Free-text labels for this event's `CATEGORIES` line, in the order they
     * should appear. Empty means the line is left out entirely.
     *
     * A kind may still override this when its categories live somewhere of its
     * own — in its profile or in a column it adopted. Strings, not finished
     * iCalendar: escaping, separators and folding stay in the package, or they
     * end up built a second time and wrong.
     *
     * What changed in v0.61.0, and why: before, the default was an empty array
     * and every kind had to build its own storage. Measured across the four
     * products on 18.10.2026, the result was that categories worked *nowhere* —
     * one kind said yes without having anywhere to put them, another had the
     * column and said no. So the package now keeps the assignment itself, and
     * a kind only has to agree to carry categories.
     *
     * Ordering comes from the category list, not from the order someone
     * assigned them: the maintained list has a `sort_order`, and a report whose
     * rows move depending on who clicked first is not a report.
     *
     * @return array<int, string>
     */
    public function categories(CalendarEvent $event): array
    {
        if (! $this->usesCategories()) {
            return [];
        }

        // Ohne die Zuordnungstabelle gibt es nichts zu lesen — und die Frage
        // zu stellen waere ein Fehler, nicht eine leere Antwort. Siehe
        // CategoryRegistry::assignmentsAvailable().
        if (! app(CategoryRegistry::class)->assignmentsAvailable()) {
            return [];
        }

        return $event->assignedCategories
            ->map(fn (CalendarCategory $category) => (string) $category->field('label'))
            ->filter(fn (string $label) => $label !== '')
            ->values()
            ->all();
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
     * Fields of this kind's profile that may also be handed to the EVENT, flat.
     *
     * Without this, moving a field out of the shared table means rewriting
     * every place that reads or writes it — and in a grown application that is
     * dozens of files, each one a chance to get it wrong. With it, the field
     * keeps its name: `$event->project_id` and
     * `CalendarEvent::create(['project_id' => 7])` go on working while the
     * value lives in the profile.
     *
     * ## How the two states behave
     *
     * As long as the core table still HAS the column, the value is written to
     * both and read from the core — nothing to roll back if a deployment goes
     * backwards. Once the column is dropped, the event stops writing it and
     * the profile answers the read. No flag day, no coordinated release.
     *
     * ## What this does NOT cover
     *
     * Queries. `where('project_id', 7)` on the events table asks the database,
     * not the model, and no accessor reaches that far. Those places have to be
     * converted before the column can go — which is exactly the point of
     * naming the fields here: the list is the to-do list.
     *
     * @return array<int, string>
     */
    public function profileFields(): array
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
