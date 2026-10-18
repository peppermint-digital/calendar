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

## The interface

The package ships its front end in the same repository: `composer.json` for the PHP core,
`package.json` for the React components. One tag serves both.

```
composer require peppermint/calendar
npm install github:peppermint-digital/calendar#v0.34.0
```

```tsx
import { AgendaList, EventForm, emptyDraft } from '@peppermint-digital/calendar';

<EventForm
    kinds={kinds}                 // key, label, requires, forbids, usesCategories
    categories={categories}
    value={draft}
    onChange={setDraft}
    onSubmit={save}
    extraFields={<ProjectAndBilling />}   // whatever only this product has
/>
```

**Which fields appear is decided by the kind, not by the form.** What `rules()` requires is
marked and checked before submitting; what `forbiddenAttributes()` forbids is not rendered at
all. The same dialogue therefore shows four fields in a personal calendar and twelve in a work
calendar without existing twice — and it does not care whether the kind is its own or another
system's, because `kinds()` on a writable source returns the same shape.

The product passes its own fields in rather than rebuilding the arrangement. A component that
only supplies the pieces leaves every application to wire them up, and the wiring is where they
drift apart.

No component library: plain elements with Tailwind class names, styled by the host. A UI
dependency would force itself on every consumer, and two of them in one tree rarely agree.

## Vocabulary

Six words that are easy to mix up, and the line between them. Getting this wrong is how a
calendar ends up with two half-working ways to say the same thing.

| Word | What it is | Where it lives | Who creates one |
| --- | --- | --- | --- |
| **Kind** | What an event *is*. Decides which extra fields exist, how deletion behaves, what is required. | A class, registered in config | A developer, in a release |
| **Category** | A label *within* a kind — the `kind` column says which kind offers it, empty means every kind. Optional feature; a product without categories has none. | Rows in a table | Anyone, at runtime |
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
| Category slug | lowercase snake case — **the key several systems agree on**, so filtering by it works across them | `customer_visit` |
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

## Moving a field out of the core table

A grown calendar table collects fields that belong to one kind: a project, a
leave request, a billing flag. They are in the way for every other application
that installs this package — it has no projects, and it still carries the
column.

The field moves into the kind's profile. What it does NOT have to do is move in
the code:

```php
class BusinessKind extends EventKind
{
    public function profileModel(): ?string { return BusinessProfile::class; }

    public function profileFields(): array  { return ['project_id', 'is_not_billable']; }
}
```

From here on the field keeps its name, wherever the application touches it:

```php
CalendarEvent::create(['kind' => 'business', 'project_id' => 7, ...]);   // lands in the profile
$event->project_id;                                                      // answers from the profile
$event->update(['project_id' => 9]);                                     // updates the profile
$event->project;                                                         // the relation still resolves
```

### Two states, no flag day

| Core column | Written to | Read from |
| --- | --- | --- |
| still there | core **and** profile | core |
| dropped | profile | profile |

Writing both while the column exists is what makes the move reversible: a
deployment that goes backwards finds the value where the old code looks for it.
Dropping the column needs no code change — the event notices and stops writing
it.

### What it does not cover

Queries. `where('project_id', 7)` and `whereHas('project')` ask the database
about the events table; no accessor reaches that far, and neither does
`insert()` or a mass `update()` on the query builder. Those places have to be
converted to the profile before the column can go:

```php
CalendarEvent::whereIn('id', BusinessProfile::where('project_id', 7)->select('event_id'))
```

Naming the fields in `profileFields()` is therefore also the to-do list: the
accessors buy time for everything that goes through the model, and the queries
are what is left to do by hand.

And one more: a **narrow select**. `select(['id', 'title'])` leaves `kind` behind,
and without the kind the event cannot tell which profile to ask — the field reads
as `null`, quietly. Include the kind column, or do not read profile fields off
that result.

Reading never throws, though: an unknown or empty kind makes a profile field
answer `null` rather than raise. Saving is the opposite — there a guessed kind
would decide fields, visibility and deletion behaviour, so it stops.

### Fields a kind has no place for

A grown form posts every field it knows, empty ones included — `'organization_id'
=> null` reaches a private appointment too. A wide core table absorbed that
silently, which is the width this whole mechanism removes. So:

| value | what happens |
| --- | --- |
| empty, and the kind has no place for it | left out — nothing was there to lose |
| filled, and the kind has no place for it | `AttributeWithoutHome` |

That is a different question from `forbiddenAttributes()`, which says what a kind
must not carry even though a column exists. This one says there is no place at
all.

## Two ways to hang something on an appointment

Mixing these two up is what makes a core table wide.

**Profile** — 1:1, belongs to ONE kind. Only business appointments carry a
category. A kind with no extra fields needs none.

**Satellite** — belongs to a MODULE and works for EVERY kind. Attendees are the
model: `calendar_event_attendees` hangs off `event_id` and works for leave, for
a planned block and for a habit alike, without any of those kinds knowing it
exists.

```php
use Peppermint\Calendar\Schema\CalendarSchema;

// in the notes module's own migration
CalendarSchema::satellite('calendar_event_notes', function (Blueprint $table) {
    $table->foreignId('note_id')->constrained()->cascadeOnDelete();
});
```

Install the module and the table and its interface exist. Don't install it and
the feature does not exist — and there is no column anywhere. In one sentence: a
satellite needs no cooperation from the kinds, a profile field needs all of them.

## Time blocking

The server side has been here since the beginning: `TimeBlocking\Board::build()`
takes whatever the application offers for planning, splits it into open and
scheduled, and returns the events, the collisions and the window. It handles
several kinds of subject at once — tasks, habits, tickets — because ids are only
unique within their own type.

What was missing is the other half, and two applications built it separately:
the list you drag from.

```tsx
import { PlannableList } from '@peppermint-digital/calendar';

<PlannableList
    items={open}
    renderItem={(item) => <TicketCard ticket={item} />}
    eventData={(item) => ({ kind: 'verwaltung_ticket', ticketId: item.id })}
/>
```

The package owns the wiring — the container, the data attributes the drop reads,
and the rebuild. That last one is the point: after every redraw of the list
(search, tab change, week change) FullCalendar's `Draggable` has to be built
again, or the fresh cards look draggable and are not. Both applications had to
discover that on their own.

The look stays with the application, through `renderItem` — same reason as
`renderButton` on the toolbar: a second button style in the middle of a finished
interface shows immediately.

## The planned block

A task, a ticket, a deal planned into the calendar: the appointment is not the
thing itself, it is its planning. This shape turned up in four systems
independently and was built **three different ways** — two with a profile and a
real foreign key, one with no profile at all, on `subject_type`/`subject_id`.
Three answers to one question, so nothing could be shared: no common query, no
common conflict rule, no common display.

The kind itself cannot be shipped — the subject differs per system, and a
foreign key can only point at a table that exists. What ships is the shape:

```php
class TicketBlockKind extends PlannedBlockKind
{
    public function key(): string          { return 'verwaltung_ticket'; }
    public function label(): string        { return 'Ticket block'; }
    public function subjectModel(): string { return Ticket::class; }
}

class TicketBlockProfile extends PlannedBlockProfile
{
    protected $table = 'calendar_event_profile_verwaltung_ticket';
    protected string $subjectModel = Ticket::class;
    protected string $subjectKey = 'ticket_id';
}

// migration
CalendarSchema::plannedBlockProfile('verwaltung_ticket', 'tickets');
```

The base class brings the three decisions each system was making for itself:
not user-creatable (a block comes into being by dragging something into a day),
no trash (a block whose subject is gone marks time as busy that is free), and
the foreign key in the profile rather than in the core.

It also **fills `subject_type` / `subject_id` from that foreign key** and
refuses to save a block without a subject. The pair and the key say the same
thing and do different work: the pair is what `PlannedSubjects` and
`TimeBlocking\Board` read — they do not know your kinds — while the key is the
one the database watches.

Leaving that to each application does not hold. Measured in a production
system on 2026-10-14: `subject_type` was null on **all 1207 events**, with 484
time blocks hanging off the foreign key alone. The board would have found
nothing there and shown every scheduled task as still open. A rule every
application has to remember is a rule some application forgets.

`saving()` is `final` for the same reason; override `savingBlock()` when a
kind needs to do something of its own.

## The whole form, in one component

`EventForm` draws the fields. `EventComposer` draws **the whole task**: pick the
target system, draw that system's fields, save here or over there.

```tsx
<EventComposer
    kinds={Object.values(kinds)}   // what THIS system offers
    targets={targets}              // other systems that accept events
    value={event}                  // null when creating
    onSaveHere={(draft) => post('/calendar/events', draft)}
    onCancel={() => router.visit('/calendar')}
    onSavedThere={() => router.visit('/calendar')}
    extraFields={<ProjectAndTask … />}
/>
```

Your app supplies four things: its kinds, its targets, its own save path, and its
own extra fields. Everything else lives in the package — the target picker, the
switch to the other system's kinds, the draft state, the error line, the remote
create.

**Why this is a component and not a recipe.** Until 15.10.2026 every system
assembled the same pieces itself — `useRemoteTarget`, `emptyDraft`, the picker,
the kind switch. Measured on that day:

| System | lines |
|---|---|
| CRM | 301 |
| Verwaltung | 280 |
| Manager | 728 |
| AI Brain | 1348 |

Same parts, four environments. The consequence was predictable and it happened: a
change in behaviour needed four rewrites, the third one drifted, and the fourth
was left behind. On 14.10. a fix shipped to two systems and not to the other two
— and *half* is worse than either end, because now the dialog differs depending
on where you open it.

### Two things change when a foreign target is picked

Both follow from one sentence — **over there, the other system's rules apply**:

1. The form draws from the **target's** kind, not yours. Which fields it carries
   is what that kind's `requires` / `forbids` say.
2. `extraFields` disappear. Creating from the CRM into the Manager, there is no
   deal over there — a field for it would take input that arrives nowhere.

The kind picker inside the form *is* the target-kind picker while a foreign
target is selected. A second list beside it drifts the moment someone touches
only one of them.

### When your app needs to hold the draft

By default the composer holds it. Pass `draft` + `onDraftChange` when your page
needs it outside — the Manager mirrors it into its payload through an effect
(`start_datetime` from date and time, the recurrence rule) and its extra fields
read along. A composer that locked the draft away would force such pages into a
second state beside it, and two states for one thing drift.

```tsx
<EventComposer draft={draft} onDraftChange={setDraft} … />
```

In controlled mode the composer does not write — it reports and lets your app
decide.

### Preselecting a target

`initialTarget` is for the „create elsewhere" path out of a choice dialog: the
person already picked the system and should not pick it again in the form. The
Manager used to route that path into a *separate little dialog with its own axios
calls* — the fifth copy of the same form. Now it routes into the same one.

The target is chosen **once**, not on every render: `chooseTarget` fetches the
other system's kinds, and a network call per render is a loop nobody notices
until the other system starts throttling.

### Making it look like your app

The package draws with plain elements on purpose — depending on a component
library would force it on every consumer, and two libraries in one tree rarely
get along. That must not mean the form looks foreign:

```tsx
renderSection={(children) => <Card><CardContent>{children}</CardContent></Card>}
```

Both sections — target picker and form — go through it. In a dialog, pass the
children straight through: the dialog *is* the wrapper.

### What stays with your app

`onSaveHere` — because only your app knows its route, its payload and its extra
fields. The foreign path lives in the package because it is the same everywhere.

## One address per page

```php
Route::middleware(['web', 'auth'])->group(function () {
    CalendarRoutes::pages(CalendarController::class);
    CalendarRoutes::germanRedirects();   // for products that used to speak German
});
```

| | |
| --- | --- |
| `calendar` | `calendar.index` |
| `calendar/create` | `calendar.create` |
| `calendar/agenda` | `calendar.agenda` |
| `calendar/{event}` | `calendar.show` |
| `calendar/{event}/edit` | `calendar.edit` |

On 16.10.2026 "create an appointment" meant four different things across four
systems: `/calendar/create`, `/calendar/neu`, and twice
`/kalender/termine/neu`. One form, four addresses — four sets of bookmarks,
four sets of links, and no common URL you could write down anywhere.

English, because the code is. The German paths of the two older systems stay as
permanent redirects: an address that once worked is cheap to keep and expensive
to lose.

**The controller stays with the product.** It knows this system's database, its
permissions and its fields. Shared are the addresses and the names, not the
behaviour behind them.

`create` and `agenda` are laid *before* `{event}` — the other way round the
placeholder swallows them, `/calendar/create` becomes an appointment with the
id "create", and the page 404s instead of showing the form. Pass `only` to lay
just the operations your controller actually serves; the AI Brain deletes by
file path rather than row id, so its `destroy` does not fit the scheme.

## The page the calendar sits on

`CalendarBrowser` is the arrangement: head on top, view toolbar and grid in a
card below it, and — when the product has one — a column beside the grid for
whatever is still to be scheduled.

```tsx
<CalendarBrowser
    title={<h1 className="text-3xl font-bold">Kalender</h1>}
    actions={<><Filters … /><Button>Termin erstellen</Button></>}
    notice={overlaps > 0 ? <OverlapHint count={overlaps} /> : null}
    toolbar={<CalendarToolbar … />}
    grid={<CalendarGrid height="100%" fillsContainerFromLg … />}
    aside={<PlannableList … />}       // leave out and the grid takes the width
/>
```

### Why the arrangement is in the package and not in the product

It is the part every product would write identically. Measured on 15.10.2026,
across four systems that all already drew their grid and their toolbar from
this package:

| System     | frame        | full height | column beside |
| ---------- | ------------ | ----------- | ------------- |
| Manager    | one card     | `lg:flex-1` | no            |
| CRM        | six cards    | `xl:flex-1` | yes           |
| Verwaltung | eight cards  | `xl:flex-1` | yes           |
| Brain      | no card      | none        | no            |

Four arrangements, and the arrangement is the part you actually see. Put four
calendars side by side and you do not see the components — you see where they
sit and how tall they are. The Manager is the template here: it is the
most-used system, and its version is the only one where the grid really uses
the window height.

### Who scrolls

From `lg` up the frame is as tall as the window and clips. The grid gets the
remaining height and scrolls inside it — a week from 7 to 23 fits no screen,
but the head with "Termin erstellen" has to stay put.

Below `lg` it does not: the filter row wraps so far there that no usable height
is left for the grid. The page scrolls as a whole. Pass `fullHeight={false}`
for a page that should scroll everywhere.

### The column beside the grid is a slot, not a decision

CRM and Verwaltung put a list of what is still unscheduled next to the grid and
drag from it into the grid. Manager and Brain have nothing to put there. Both
are right — it depends on whether schedulable things arise in that system at
all. So the frame does not decide it; filling `aside` does, the same way
`MailboxBrowser` handles its sidecar.

Two columns only from `xl`, and that is measured rather than guessed: stacked,
the list and the grid are never both on screen at once, and "drag it into the
grid" then needs a scroll mid-drag. That is what the first attempt in the
Verwaltung failed on.

### Classes are text, never assembled

`heightClass` and `asideWidth` take finished Tailwind classes. They are never
composed (no `xl:w-${n}`): Tailwind reads source and sees no variables — an
assembled class silently produces no CSS.

If you pass your own value, it also has to appear as text in **your** file,
because Tailwind scans the product's project. The defaults live in the
package's own source and are built along with it — provided your `app.css`
carries

```css
@source '../../node_modules/@peppermint-digital/calendar/dist';
```

Without that line, exactly those classes go missing that only the package uses,
and silently: the page builds, the component appears, it just sits wrong. On
15.10.2026 that line was missing in three of the four systems — and `w-28`,
`w-44` and `text-amber-600` were in fact absent from the CRM's CSS.

### What a single entry carries

```tsx
{
    id: 'schedule_12',
    title: 'Abgabe',
    start: '2026-03-09T09:00:00',
    end: '2026-03-09T10:00:00',   // leave out for a point in time
    allDay: false,
    colour: '#1e3a8a',
    textColour: '#ffffff',        // else FullCalendar picks, and legibility moves with the colour
    editable: true,
    durationEditable: false,      // movable, but "due for two hours" makes no sense
    tooltip: ['Abgabe', '📁 Website'],
    payload: event,               // comes back through the handlers unchanged
}
```

`durationEditable` left out means "same as `editable`". It is not passed on to
FullCalendar then — an explicit `undefined` would override the global option,
and on a phone dragging is deliberately off.

### Moving and resizing are two things

```tsx
<CalendarGrid
    onEntryMove={(payload, start, end, change) => …}
    onEntryResize={(payload, start, end, change) => …}   // falls back to onEntryMove
/>
```

Moving changes the start, resizing only the end. Send both to the same route
and a resize quietly moves the entry as well.

The fourth argument carries what the first three cannot say:

| | |
| --- | --- |
| `change.allDay` | whether the entry landed in the all-day row. Without it, the first drag turns every all-day event into a timed one |
| `change.end` | the end as it is — `null` for a point in time. The third argument cannot express that and points at the start instead |
| `change.revert()` | put it back: the server refused |

`revert` matters wherever the change goes through an API call rather than a
page load. Without it a rejected move stays on screen while the server holds
the old time — which is worse than an error, because it looks like success.
Over Inertia you do not need it: the entries come back fresh anyway.

### Planning something that already exists

```tsx
<PlannableSearch
    search={(term) => fetch(`/api/calendar/plannable-tasks?q=${term}`).then((r) => r.json()).then((d) => d.tasks)}
    onPick={(id) => scheduleIt(id)}
/>
```

"Plan an existing task" is not a task operation as far as the calendar is
concerned — it is a `PlannedBlockKind`: an entry carrying a `subject_type` and
`subject_id`. The calendar models that already; what it lacked was a way to
*find* the subject.

Creating a *new* task does not belong here. Title, priority and project are
vocabulary of the task system, and a form for them in this package would mean
the calendar knowing about someone else's database. Link out instead, with the
time range in the query.

`search` is a function, not a path. Every system asks its own endpoint — the AI
Brain `/api/calendar/plannable-tasks`, the Verwaltung its open tickets. A fixed
path would have forced all of them to build the same endpoint.

**Pass no `search` and the whole route disappears.** That is the modularity:
with no task system plugged in there is nothing to plan, and the form does not
ask.

### The stylesheet

```css
/* in your app.css, after the Tailwind import */
@import '@peppermint-digital/calendar/calendar.css';
```

It does three things: bind FullCalendar's own colour variables to the shadcn
tokens so the calendar follows every theme change by itself; draw the drag
strip to the left of an event; and make the grid usable on a phone.

The drag strip is the one that looks like leftover styling and is not. Beside
an event a narrow lane stays free, and you drag a new appointment out of it.
Out of the box that lane sits on the *right* and is about 5px wide — in the
last column that is unreachable, because the scrollbar sits there. Widening
the right margin does not help: Chrome grows the scrollbar on hover, so the
target moves exactly when you aim at it. On the left no scrollbar can get in
the way, in any column.

On a phone, FullCalendar's `fc-timegrid-slots` layer sits above the columns
with `z-index: 1`. That keeps the hour labels visible and swallows every
touch: tapping an event did nothing, because the tap ended in the hour layer
above it. So the hour layer passes touches through, and only the labels take
them back — tapping one creates an entry there.

Measured on 15.10.2026: of four applications, two carried their own copy of
this (overlapping by half — one of them said in a comment that it had been
copied from the other), and two carried none at all. The two without had
neither the drag strip nor the touch handling.

Needs the shadcn colour tokens (`--border`, `--muted`, `--primary`,
`--foreground`, `--destructive` and their `-foreground` partners). An
application without them gets a colourless calendar, not a broken one.

### The date field

Typed German (`TT.MM.JJJJ`), kept ISO on the outside — and that is the default
now, like the time field.

`<input type="date">` follows the *browser's* language, not the application's.
On an English-set Chrome a thoroughly German form shows `mm/dd/yyyy`, which for
a date is not merely unfamiliar but ambiguous: the 9th of August and the 8th of
September look the same. Measured on 15.10.2026 in three of four applications
at once.

Input is forgiving: `8.9.26` is accepted, and so is `2026-09-08` — people paste
dates out of mails and other forms, and rejecting that would mean silently
doing nothing. Incomplete input is not reported upward: someone who has typed
`8.` is not finished, and a value that escapes on every keystroke leaves the
draft sitting on dates nobody meant.

The native field used to block earlier days through `min`. A text field cannot,
and `min` only ever worked in the picker anyway — typing went straight past it.
So the form now carries the rule itself and says "Das Ende liegt vor dem
Anfang." when it applies.

### The time field

Two dropdowns (hour : minute), not `<input type="time">` — that is the default
now, and `renderTime` remains for the product that genuinely needs something
else.

The native field follows the *browser's* language setting. Set to English it
renders a twelve-hour field with AM/PM, inside a calendar that counts 24 hours
everywhere else. Nobody notices until someone on an English browser sets an
appointment to "07:00 PM". Two lists show the same thing everywhere, and they
are faster: the times you need in a working day sit almost entirely on the
five-minute grid, and two clicks beat typing four digits.

`minuteStep` sets that grid (default 5). A stored time rarely sits on it — a
clocked-in time is 08:37, not 08:35 — so the current minute is always sorted
in. Without that, Radix falls back to the placeholder: the value is there, just
invisible, and gone on the next save.

This lived only in the Peppermint Manager until 15.10.2026 and reached the form
through `renderTime`. The other three calendars showed the native field: one
form, two different inputs.

### The day it opens on

```tsx
<CalendarGrid initialDate="2026-03-09" … />
```

Only for the first render, like FullCalendar's `initialDate`; after that use
`ref.api()?.gotoDate(…)`. Needed where the visible range lives in the URL, so a
reloaded tab brings the same window back instead of falling to today.

## When the grid sits in a card

`height: '100%'` presumes the container has a **definite** height. Without one
it resolves to zero and the grid disappears — no error, no warning, just gone.
That hits every layout where the calendar sits inside a card or a tab.

```tsx
<CalendarGrid
    height="100%"
    fixedHeight={480}          // used when the container has no definite height
    fillsContainerFromLg       // fill the container from 1024px up, fixed below
    …
/>
```

`fillsContainerFromLg` is for columns that get their height from a neighbour:
above Tailwind's `lg` the container is definite, stacked below it is not. Keep
that breakpoint in step with the `lg:` classes around it.

Without `fixedHeight` nothing changes — `height` is used as given.

## Planned time on a subject

`PlannedBlocks` shows what is scheduled on *something* — a task, a ticket, a
deal — with the total, and lets you add and remove:

```tsx
<PlannedBlocks
    blocks={blocks}
    onAdd={() => setDialogOpen(true)}
    onRemove={(block) => router.delete(`/planning/${block.id}`)}
    renderExtra={(block) => <BookedBadge block={block} />}
/>
```

It lives here and not in the task package for the same reason
`PlannedBlockKind` does: this is not about tasks. A block hangs on a subject,
and the core already carries `subject_type` / `subject_id`. Put the component in
the task package and a ticket system would have to pull in tasks to schedule its
tickets — and that package is pure PHP, so it would need a JS build first.

Leave out `onAdd` or `onRemove` and the button is not drawn: a read-only view
shows the planning without promising something that gets rejected on click.
`renderExtra` is where product-specific detail goes — who scheduled it, whether
it has been booked as a time entry. The Manager tracks both; the package needs
to know neither.

Two details that are easy to get wrong and are handled here: a block from 22:00
to 01:00 is three hours, not minus twenty-one. And the date is read as local
time — `new Date('2026-11-03')` is midnight UTC and becomes the 2nd west of it,
which would put the block on the wrong day.

## Writing into another system's calendar

Reading a remote calendar is one half; the other is creating an event *over
there*. `useRemoteTarget` carries that sequence — pick a target, load **its**
kinds, create, move, delete:

```tsx
const remote = useRemoteTarget({
    sources,                       // what your app knows about other systems
    headers: () => csrfHeaders(),  // your app's CSRF, not ours
    onDone: reload,
});

await remote.chooseTarget('manager');   // loads the Manager's own kinds
await remote.createThere({ date, start, end, title });
```

The server half is already in the package — `CalendarRoutes::sources()` plus
`EventSourceController`. Routes are **laid, not inherited**: a package that
registers its own routes also decides their prefix, auth and throttling, and
these are write paths into foreign systems.

```php
Route::middleware(['web', 'auth'])->prefix('api/calendar')->group(function () {
    CalendarRoutes::sources();
});
```

**One rule is worth knowing before you reimplement this:** a timed event must be
sent **with a timezone**. Sent as bare wall-clock time, the receiving system
reads it in *its own* application timezone — 09:00 Berlin became 09:00 UTC, in
the column, not just on screen. An all-day event is the exception: there the
bare date stays, because converting it would push the event across midnight.
`remoteMoment()` holds both halves of that rule.

`CalendarSchema` also checks MySQL's 64-character identifier limit **before** the
migration runs — SQLite does not enforce it, so a green suite says nothing about
it, and the generated name of a foreign key adds up faster than anyone counts.

## What the kind decides

| Method | Question it answers |
| --- | --- |
| `key()` / `label()` | how the kind is stored and shown |
| `profileModel()` | which table holds the extra fields |
| `profileFields()` | which profile fields may be passed flat on the event |
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

### Changing one occurrence, or all the ones after it

Three ways to edit a series, and a calendar needs all three:

```php
$editor = app(SeriesEditor::class);

$editor->cancelOccurrence($series, $tuesday);              // just this one, gone
$editor->detachOccurrence($series, $tuesday, [...]);       // just this one, changed
$editor->splitSeries($series, $october, ['recurrence_rules' => [...]]);  // this one and all after
```

The third is the one that is easy to leave out and impossible to fake. Someone who
wants Thursdays from next month means neither "this Tuesday" nor "every Tuesday that
ever was".

It produces **two series**, not one that changes its rule halfway: the old one ends the
day before, a new one starts at the split. A rule that changes in the middle cannot be
expressed as iCalendar, and applying it backwards would rewrite what already happened.

Exceptions move with it — cancellations before the split stay with the old series, later
ones go to the new one. Without that a long-cancelled occurrence quietly reappears. And
splitting at the first occurrence changes the series in place rather than leaving an
empty one behind, because there is nothing before it to keep.

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

Export runs through [spatie/icalendar-generator](https://github.com/spatie/icalendar-generator).
RFC 5545 is detail work with sharp edges — folding counted in octets, the order escapes have to
be applied in, the exclusive `DTEND` of all-day events, timezone components, `PARTSTAT`. Someone
else maintains that better than we would on the side.

```php
app(IcsExporter::class)->calendar($events, 'Team calendar');
app(IcsExporter::class)->download($events, 'team.ics');
app(IcsExporter::class)->component($event);   // for a calendar you assemble yourself
```

Three things the library does not do, and this package adds:

**`CATEGORIES`** does not exist there. Appending it through a text property escapes the comma —
and in `CATEGORIES` the comma is the *separator*, so two categories would collapse into one
called `Health\, Sport`. Each value is escaped on its own and joined with raw commas instead.

**`UNTIL` inside an `RRULE` loses its `Z`.** RFC 5545 §3.3.10 requires UTC there when `DTSTART`
is UTC; the library carries timezones in property parameters, which an `RRULE` value cannot have.
`UtcRRule` puts it back. It disappears when upstream fixes it.

**The exclusive `DTEND` of all-day events** is not applied — a one-day event would start and end
on the same date, which makes it vanish in some clients and last zero minutes in others.

`IcsWriter` keeps two methods, `escape()` and `foldDocument()`, for applications that assemble
their `.ics` by hand and cannot switch in one go. It is a transition, not a destination.

## Deliberate design decisions

**Kinds are classes, not rows.** A kind answers four questions — what am I
called, which profile holds my fields, what do I never carry, may a person pick
me. Three of those are *behaviour*, and `saving()` is outright code. A table
could store the names but not the rules, so the rules would move somewhere else
and the kind would become a label pointing at logic held elsewhere.

The price is that adding a kind needs a deploy. The gain is that it needs **no
migration** — a class with four answers, and it exists. That trade is right for
anything that decides something. For a pure label without behaviour a table
would be right; `event_categories` was exactly that, and it quietly ended up
carrying the brand of an event, which is not a label at all.

**The core table must exist, and must stay thin.** `calendar_events` holds what
*every* system understands: title, start, end, all-day, kind, visibility. That
shared floor is what makes a federated calendar possible at all — a foreign
event can only be drawn because its title and timespan mean the same thing
everywhere. Without it, each system would need to know every other system's
fields, and you would have four calendars rather than one.

Everything beyond that belongs to the kind's profile or to a module's satellite.
On 13./14.10.2026 seven product columns were lifted out of the core for exactly
this reason. Thinner is better; gone is not.

**The calendar does not know about tasks — and must not.** `PlannedBlockKind`
describes *something planned*, not a task: "a task, a ticket, a deal: the event
is not the thing itself, it is its scheduling." The core carries
`subject_type` / `subject_id` and nothing else about what that subject is.

So the dependency runs **from the task package to the calendar**, never the other
way. A task has a due date and a planned timespan; the calendar is the instrument
for setting and seeing them. A task package brings its own kind, its own fields
and its own small calendar inside the task view — and uses this package for all
of it.

Pointing the arrow the other way would mean the calendar needs to know what a
task is, and then what a ticket is, and then what a deal is. That is the version
of this package that cannot be installed by anyone who has none of those things.

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

### Creating in another system

A source shows what lives elsewhere. A **writable** source also lets someone create there —
a meeting in the work calendar, entered from the private one.

```php
class ManagerSource extends WritableEventSource
{
    public function kinds(): array
    {
        return [new ExternalKind('business', 'Business', requires: ['location'])];
    }

    public function create(int $userId, NewExternalEvent $event): ExternalEvent
    {
        return $this->toExternalEvent($this->client->create($userId, $event->toArray()));
    }
}
```

Deliberately a separate class rather than a flag on `EventSource`. A flag is a check someone
forgets, and what it guards is a write path into a system that was only meant to be shown.
A read-only source stays read-only because it does not have the method.

Two details that decide whether this works in practice:

**Kinds are per product.** What one application calls "business" the next has never heard of,
so the target system names its own — with the fields it will reject without. A dialogue can
then ask for them instead of showing the rejection after the fact.

**What comes back is the target system's version, not the one that was sent.** It rounds
times, fills in defaults, attaches its own id. Show its answer, or the calendar says something
different from the system that owns the event.

The event belongs to the other system from the first moment: no row here, exactly as when
reading.

### One source class for every connected system

Writing a source per remote system works while you know at build time which systems exist. It
stops working once they arrive at runtime — connected products that announce their own
capabilities. Then every new system needs a class *and* a line of configuration in every other
system, and that line is the one somebody forgets.

`RemoteEventSource` is that class, written once. What differs between applications is not the
mechanism — ask, map, tell failures apart, resolve the person — but the **way** to the other
system: a hub calls its connected products over its own gateway, a product calls over its
client library, and a third has neither. So the way is a contract:

```php
use Peppermint\Calendar\Sources\RemoteCalendar;
use Peppermint\Calendar\Sources\RemoteEventSource;
use Peppermint\Calendar\Sources\RemoteResult;

class ManagerCalendar implements RemoteCalendar
{
    public function key(): string   { return 'manager'; }
    public function label(): string { return 'Manager'; }

    // Measured against a capability registry, never guessed: lose the tool
    // over there and the source disappears here by itself.
    public function offers(string $capability): bool { return $this->product->has($capability); }

    // Rights, which do not live in this package. Separate from offers(),
    // because a system may show its calendar without letting anyone write.
    public function mayWrite(int $userId): bool { return $this->access->allows($userId); }

    // Two systems, two user tables. null means the operation does not happen.
    public function identify(int $userId): ?string { return $this->mapping->emailOf($userId); }

    public function baseUrl(): ?string { return $this->product->url; }

    public function call(string $capability, array $arguments = []): RemoteResult
    {
        $response = $this->client->call($capability, $arguments, $this->product->slug);

        return new RemoteResult($response['ok'], $response['data'], $response['message']);
    }
}

$registry->register(new RemoteEventSource(new ManagerCalendar($product)));
```

The capability names are the package's own (`calendar.events.list`, `calendar.events.show`,
`calendar.events.create`, `calendar.events.update`, `calendar.events.delete`,
`calendar.kinds.list`) — the same six listed under *Naming*.

**For the ordinary case there is a shortcut.** The first consumer's implementation looked
product-specific. The second one was character-identical except for its user model, which is
where the contract's own question — *does every application write the same wiring?* — gets
answered with yes. So the ordinary case lives here:

```php
new RemoteEventSource(new CallableRemoteCalendar(
    key: 'verwaltung',
    label: 'Verwaltung',
    capabilities: ['calendar.events.list', 'calendar.kinds.list'],
    transport: fn (string $op, array $args) => $client->call($op, $args, 'peppermint-verwaltung'),
    identify: fn (int $userId) => User::find($userId)?->email,
    baseUrl: 'https://vw.example.com',
));
```

A closure rather than a dependency, because the way out is what genuinely differs between
applications: a hub calls its connected products over its own gateway, a product over its
client library, a third over something else. Requiring one of them here would force a
dependency on two consumers out of three that do not need it.

Without a `mayWrite` closure the rule is: whoever can be named may try. That is not
carelessness — the rights live in the target system, and a second list here would age without
anyone noticing. The refusal then comes from over there, loudly and with a reason.

`call()` must not throw. An unreachable system is the normal case, not an exceptional one:
throw there and every caller needs a `try`, and one of them will not have it. The source
decides what a failure means instead, and it is not the same answer twice — **reading stays
quiet, writing shouts.** A failed read raises so the registry can log it and leave that one
source out, keeping the rest of the calendar visible. A failed write raises
`ExternalSourceFailed` all the way to the interface, because minutes pass between opening a
page and pressing save: a source that answered on open can be dead on create, and an event
somebody *believes* they created and that exists nowhere is worse than an error message.

### The four operations, already wired

Fetching another system's kinds, creating there, moving there, deleting there — that is
wiring, and it is the same wiring in every application: pull the source from the registry,
404 when it is not writable, validate, run the operation, answer a failure with 502. Four
endpoints containing nothing that distinguishes one product from another.

```php
use Peppermint\Calendar\Http\CalendarRoutes;

Route::middleware(['web', 'auth'])->prefix('api/calendar')->group(function () {
    CalendarRoutes::sources();
});
```

**The package registers nothing by itself.** A package that registers a route also decides
how it is guarded — prefix, authentication, throttling. These are write paths into *other*
systems, which is the last place a product should hand over control. So the product lays them
where its own guards already stand, and authorization stays where the contract says it stays.

Two status codes carry the distinction that matters:

| | When | Why not the other one |
| --- | --- | --- |
| **404** | the source is not (or no longer) writable | Not an error — it is the answer to "not enabled for you". Lose the tool or the grant over there and the system disappears here by itself. |
| **502** | the operation failed over there | Not 500: this application is fine, the other one did not play along. The response names the source and the operation, because "something went wrong" sends the next person looking in the wrong place. |

Listing the sources is *not* here. Which ones exist and how they appear — colour, order,
label — is presentation, and that stays in the product.

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

In production in four systems — Peppermint Manager, AI Brain, Peppermint
Verwaltung and Peppermint CRM — with 228 tests.

In place: kinds and profiles, satellites, flat profile fields (so a field can
leave a shared core table without rewriting every caller), the planned-block
shape, attendees, scopes, per-kind deletion, recurrence, iCalendar export, the
time-blocking primitives, and remote calendars of other systems — read and
write, over whatever transport the host provides.

Not in place: CalDAV and invitation mails.

## License

MIT.
