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

## Vocabulary

Six words that are easy to mix up, and the line between them. Getting this wrong is how a
calendar ends up with two half-working ways to say the same thing.

| Word | What it is | Where it lives | Who creates one |
| --- | --- | --- | --- |
| **Kind** | What an event *is*. Decides which extra fields exist, how deletion behaves, what is required. | A class, registered in config | A developer, in a release |
| **Category** | A label *within* a kind. Optional feature; a product without categories has none. | Rows in a table | Anyone, at runtime |
| **Profile** | The kind's own table, one row per event. | A table per kind | A developer |
| **Source** | Another system's calendar, shown here and owned there. | A class, registered in config | A developer |
| **Subject** | What the event is *about* when the core cannot know: a task, a shift, a booking. | Two columns, `subject_type` / `subject_id` | — |
| **Visibility** | How openly an event may be seen. Not the kind: a business event may be confidential, a private one shared. | A core column, exported as `CLASS` | — |

The test that separates the first two:

> **A kind is structure, a category is content.**
> Adding a kind means a class, a migration and a release. Adding a category means a row.
> If a user could plausibly create it while using the application, it is a category. If it
> changes *which fields an event has*, it is a kind.

So "digital meeting" is a kind — it requires a link that an in-person meeting must not have.
"Customer visit" is a category — it changes nothing about the shape of the event.

## Naming

Names that end up in stored data or in another system's payload cannot be changed later
without breaking something. These are fixed by convention:

| Thing | Rule | Example |
| --- | --- | --- |
| Kind key | lowercase snake case, stable forever — it lives in every row | `business`, `meeting_digital` |
| Category slug | lowercase snake case, product-defined | `customer_visit` |
| Source key | lowercase, no vendor prefix — it prefixes ids and is stored in user filters | `manager`, not `acme-manager` |
| External id | `<source key>:<remote id>`, built by the package | `manager:7` |
| `extra` key | snake case, the other system's own vocabulary | `is_not_billable` |
| Capability | `calendar.events.<verb>` | `calendar.events.create` |

A kind key is the one that hurts most if it changes: it is written into every row, and
nothing in the database remembers what it used to be.

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
    public function forbiddenAttributes(): array { return ['subject_type', 'subject_id']; }
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

## Categories

An extra, not a core field. An application without categories has none — no column, no
table, no empty select next to every event.

Turning them on takes three steps, on purpose:

```
php artisan vendor:publish --tag=calendar-categories
```

then run the published migration, then set `categories.enabled` and give the kinds that
have them `usesCategories()`.

### How far the list may grow

```php
'categories' => [
    'enabled' => true,
    'mode' => 'personal',            // closed | personal | open
    'create_ability' => 'categories.create',
    'defaults' => [
        ['slug' => 'health', 'label' => 'Health', 'colour' => '#10b981'],
    ],
],
```

| Mode | Who may add one |
| --- | --- |
| `closed` | nobody — only the managed list |
| `personal` | everyone, **for themselves** |
| `open` | everyone, for everyone |

`personal` is the default because it answers both failure modes at once. A list nobody may
touch gets worked around — people put the missing word in the title. A list everyone may
extend for everyone fills up with near-duplicates until it means nothing. In between: a
freely typed category belongs to the person who typed it, and an administrator can promote
it to the shared list. That promotion is a decision someone makes, not a side effect.

Comparison runs over a slug, so `Sport`, `sport` and `  SPORT  ` are the same category
rather than three. Whether a person may create one at all is the application's call: the
package knows the name of an ability, not what it means.

### Where the link lives

The package owns the **list**, not the connection. Whether an event points at its category
through the profile of its kind or through a column of your own adopted table is your
decision — and it stays out of the shared events table either way.

### On export

`CATEGORIES` is a comma-separated list of free text in RFC 5545 — no registry, no ids. So a
managed list with ids at home travels as plain labels, and the kind says which:

```php
public function categories(CalendarEvent $event): array
{
    return array_filter([$event->profile?->category?->label]);
}
```

Empty means the line is left out; an empty `CATEGORIES:` is noise some clients trip over.
The kind returns strings and never finished iCalendar — escaping, separators and folding
stay in the package. That matters more than it looks: the comma is the *separator* here
while escaping turns a comma into `\,`, so a naive `implode` collapses two categories into
one called `Health\, Sport`.

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

Stored rules are not appearances: a series is one row, and the dates it produces
are computed when a window is read.

```php
$occurrences = app(OccurrenceExpander::class)->expand($events, $from, $to);
```

Expanding into rows would mean every rule change has to rewrite them — and a
stored occurrence can disagree with its rule, at which point nobody knows which
one is right. Events without a rule pass through unchanged; a rule that cannot
be read leaves its event visible once, at its own date, rather than making it
disappear.

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

**Forbidden fields are checked on change, not on existence.** Applications adopt
kinds for data that predates them. A guard that rejects an untouched legacy value
would turn "you may not add this" into "you may never save this row again", so
only attributes being set or changed are examined.

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

## Calendars of other systems

An event kind describes events this application owns. A **source** describes
events that belong to another system and are only displayed — no rows here, no
editing, no copy that drifts out of sync.

```php
class ManagerSource extends EventSource
{
    public function key(): string   { return 'manager'; }
    public function label(): string { return 'Manager'; }

    public function events(int $userId, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->client->appointments($userId, $from, $to)
            ->map(fn ($row) => new ExternalEvent(...))
            ->all();
    }

    // Skipped silently where the connection is not configured.
    public function isAvailable(): bool { return $this->client->isConfigured(); }
}
```

Neither side is the centre: each application registers the sources it wants to
see, and the same package serves both directions. A source that fails is logged
and left out — the rest of the calendar still appears, because a blank page
explains nothing while a gap is at least visible.

### Fields only the other system knows

A source usually has more to say than the eight fields every calendar shares: a
project, a customer, a billing flag, who is attending. Those go into `extra`.

```php
new ExternalEvent(
    sourceKey: 'manager',
    id: (string) $row['id'],
    title: $row['title'],
    startsAt: CarbonImmutable::parse($row['starts_at']),
    endsAt: CarbonImmutable::parse($row['ends_at']),
    extra: [
        'project'  => $row['project']['name'] ?? null,
        'category' => $row['category'] ?? null,
        'billable' => ! $row['is_not_billable'],
    ],
);
```

They are shown and nothing else — never stored, never written back, never mapped
onto core columns. This is the answer to the pull every shared calendar table
feels: a product needs one more field, and the cheapest place looks like a new
column in the middle. A column would land in every other product too. This does
not, because an external event is never persisted.

Keys that collide with what the core writes (`id`, `starts_at`, `external`, …)
are dropped. Without that, a remote system could overwrite the identity of its
own event by naming a field cleverly — and the event could then be slipped into
something that writes. When `extra` is empty the key is left out entirely: a key
that is always present but usually empty teaches readers to ignore it.

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
