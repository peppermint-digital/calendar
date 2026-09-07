# Calendar

A calendar core for Laravel that does not know a single event type — your application does.

Most calendar packages ship with a fixed event table: a column for every feature
anyone might need, and a `type` column to tell them apart. That table only grows.
Add invoicing, add equipment bookings, add private appointments, and every
application carries every other application's columns.

This package inverts that. The core owns what every event has — a title, a time
range, attendees, recurrence, visibility, an iCalendar identity. Everything else
lives in a **profile table owned by the event kind**, and a kind is a class your
application registers.

```
calendar_events                  title, time range, all-day, location,
                                 recurrence, owner, visibility, kind, uid
  ├── calendar_event_attendees   internal and external, one foreign key
  └── <your profile table>  1:1  your columns, your constraints
```

## Defining an event kind

```php
use Peppermint\Calendar\Kinds\EventKind;

class PrivateKind extends EventKind
{
    public function key(): string            { return 'private'; }
    public function label(): string          { return 'Private'; }
    public function profileModel(): ?string   { return PrivateProfile::class; }

    // A private appointment someone deletes is gone. No trash.
    public function usesTrash(): bool         { return false; }

    // Guards the kind against becoming a meaningless flag.
    public function forbiddenAttributes(): array { return ['meeting_url']; }
}
```

Register it in `config/calendar.php`:

```php
'kinds' => [
    App\Calendar\BusinessKind::class,
    App\Calendar\PrivateKind::class,
],
```

Add a migration for the profile table with `event_id` constrained to
`calendar_events`, and you are done. The core needs no changes — not for your
kind, and not for the next one.

## What the kind decides

| Method | Question it answers |
| --- | --- |
| `key()` / `label()` | how the kind is stored and shown |
| `profileModel()` | which table holds the extra fields |
| `usesTrash()` | soft delete with a trash bin, or delete for good |
| `trashRetentionDays()` | how long deleted events stay recoverable |
| `forbiddenAttributes()` | which core fields this kind must never set |
| `rules()` | validation rules the application pulls into its requests |
| `saving()` | a hook before every save |

## Recurrence

Rules are stored as an array on the event and validated by building a
`RecurrenceRule` — there is no way to hold one that describes an impossible
series.

```php
$rule = RecurrenceRule::fromArray([
    'frequency' => 'monthly',
    'byMonthDay' => 31,
    'monthDayOverflow' => 'clamp',   // or 'skip'
]);

$dates = app(RecurrenceCalculator::class)->occurrences($event->starts_at, $rule, $until);
```

`monthDayOverflow` exists because February has no 31st and there is no answer
that is right for everyone: `skip` leaves that month without an occurrence,
`clamp` falls back to the last day of the month. It is a setting rather than a
silent behaviour, because both choices surprise somebody.

Labels come from the package's language files (English and German included), so
`describe()` follows the application's locale instead of hard-coding one.

## Time blocking

Time blocking is a way of working, not a data structure: a list of things to do
next to a calendar, and you push the day into shape. The list rules belong in
the package — written once, they cannot drift apart between two frontends.

```php
$board = app(Board::class)->build(
    userId: $user->id,
    subjectType: 'task',
    items: $tasks->map(fn ($task) => new PlannableItem(
        id: $task->id,
        title: $task->title,
        recurring: $task->is_routine,
        meta: ['project' => $task->project?->name, 'effort' => $task->estimated_hours],
    )),
    from: $windowStart,
    to: $windowEnd,
);
```

You get back what still needs planning, what is already in the day, the events
of the window, and where the day collides with itself. Your frontend only draws
it — Vue and React can render the same board without agreeing on anything but
the shape.

The rule that matters: a one-off item leaves the list as soon as it has any
block at all; a recurring one comes back in every window it is not yet planned
in. Without that distinction a routine task disappears after being scheduled
once and never returns — and nobody notices, because a missing entry looks
exactly like an empty list.

An event can point at something the core knows nothing about — the task behind a
time block, a habit, a training session — through a narrow `subject_type` /
`subject_id` pair. That is enough for the core to answer the two questions every
planner needs:

```php
$finder = app(ScheduleConflictFinder::class);
$finder->conflicts($userId, $start, $end, ignoreEventId: $movedEvent?->id);

$planned = app(PlannedSubjects::class);
$planned->ever($userId, 'task');                        // one-off: planned at all?
$planned->within($userId, 'task', $windowFrom, $windowTo);  // recurring: planned in view?
```

Back-to-back slots do not collide — 10:00–11:00 and 11:00–12:00 are consecutive,
not overlapping. Everything counts per person: someone else planning the same
task never removes it from your list, and moving an event does not collide with
the version of itself still in the database.

The distinction between `ever` and `within` is what keeps a recurring task from
disappearing forever after being scheduled once.

## iCalendar

```php
return app(IcsExporter::class)->download($events, 'team.ics', 'Team calendar');
```

Series are exported as `RRULE`, so a subscriber sees a series rather than one
appointment. Attendees carry their response state, trashed events are exported
as `STATUS:CANCELLED` so subscribers remove them, and confidential events are
marked `CLASS:PRIVATE`.

Lines are folded at 75 octets and never inside a multi-byte character — the two
things hand-rolled serialisers usually miss, because nothing breaks until
someone writes a long description or a name with an umlaut in it.

## Deliberate design decisions

**`kind` has no default.** A guessed event kind decides fields, visibility and
deletion behaviour. Creating an event without a registered kind throws — and the
exception names the kinds that *are* registered, plus where to add new ones.

**Visibility is not the kind.** A business event can be confidential; a private
one can be shared. They are two columns (`kind`, `visibility`), because a single
flag cannot answer both questions and eventually answers neither.

**`visibleTo(null)` returns nothing.** A missing identity means "unknown", not
"anyone". A scope that drops its restriction when it cannot resolve the caller
hands out everything precisely when it knows least.

**Bulk deletes follow the kind too.** `where(...)->delete()` normally bypasses
model deletion entirely; here it loads and deletes row by row, so a kind that
deletes for good is not silently soft-deleted by existing code that never heard
of event kinds.

**Dependent rows are deleted in code, not only by cascade.** Whether a foreign
key cascade fires depends on the driver — SQLite needs the pragma enabled, MySQL
does not. Behaviour that differs between your test suite and production is not
behaviour you can rely on.

## Adopting an existing calendar

An application that has kept a calendar for years has its own table and column
names, and several hundred references to them. Renaming those is a migration of
the codebase, not of the schema — so the package bends instead:

```php
'tables' => ['events' => 'calendar_events'],

'columns' => [
    'owner_id'   => 'user_id',
    'starts_at'  => 'start_datetime',
    'ends_at'    => 'end_datetime',
    'uid'        => 'caldav_uid',
],

'run_migrations' => false,   // your table already exists; bring the new columns
                             // across in a migration of your own
```

Everything else keeps working: scopes, deletion per kind, conflicts, the board
and the iCalendar export all resolve column names through this map.

## Requirements

PHP 8.2+, Laravel 11, 12 or 13.

## Installation

```bash
composer require peppermint/calendar
```

Publish the configuration with the `calendar-config` tag, then run your
migrations — the package ships its own and loads them automatically.

## Housekeeping

```bash
php artisan calendar:purge-trash --dry-run   # count first
php artisan calendar:purge-trash             # then delete
```

Each kind is purged according to its own retention period; kinds without a trash
bin are skipped, because their events never reach it.

## Tests

```bash
composer install
vendor/bin/pest
```

## Status

Early. Kinds, profiles, attendees, scopes, deletion, recurrence, iCalendar
export and the time-blocking primitives are in place and covered by tests.
CalDAV and invitation mails are next.

## License

MIT.
