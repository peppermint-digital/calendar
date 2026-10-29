<?php

return [
    /*
    | User model of the consuming application. The package has no user management
    | of its own — it only holds a reference.
    */
    'user_model' => env('CALENDAR_USER_MODEL', 'App\\Models\\User'),

    /*
    | Event kinds. Every application registers its own; the package ships none.
    | Each entry is the class name of a Peppermint\Calendar\Kinds\EventKind subclass.
    */
    /*
    |--------------------------------------------------------------------------
    | Event model
    |--------------------------------------------------------------------------
    |
    | The application's own event class. Needed since reminders can hang off an
    | event (`peppermint/reminders`): a reminder stores WHAT it is about as a
    | morph type, and that type has to be the class the product actually uses.
    |
    | Left at the package class, `subject_type` would name a model no product
    | instantiates, and looking a reminder up by its event would never match.
    | Silently — nothing would break, it just would not find anything.
    */
    'event_model' => \Peppermint\Calendar\Models\CalendarEvent::class,

    /*
    | Die Termin-Klasse der ABONNIERTEN Kalender, falls ein Produkt eine eigene
    | mitbringt. Dieselbe Begruendung wie bei `event_model`: Daran haengt der
    | Morph-Typ einer Erinnerung, und mit der Paket-Klasse stuende dort ein
    | Modell, das niemand instanziiert.
    */
    'subscribed_event_model' => \Peppermint\Calendar\Subscriptions\Models\SubscribedEvent::class,

    'kinds' => [],

    /*
    | How long a deleted event stays in the trash bin when its kind names no
    | retention period of its own. null = forever.
    */
    'trash_retention_days' => 30,

    /*
    | Calendars of other systems, shown alongside the local one.
    |
    | A source is read-only: its events live elsewhere and are only displayed.
    | Each entry is the class name of a Peppermint\Calendar\Sources\EventSource
    | subclass. Neither system is the centre — the application registers the
    | sources it wants to see.
    */
    'sources' => [],

    /*
    | Die PRIVATEN Termine der angemeldeten Person aus der Nabe (AI Brain).
    |
    | Nicht zu verwechseln mit `sources` oben: Dort stehen die Kalender der
    | anderen PRODUKTE. Hier geht es um den persoenlichen Kalender eines
    | Menschen in der Nabe, der neben seinen dienstlichen Terminen erscheint.
    |
    | Standard AN, und das ist Absicht: Der eigentliche Schalter ist die
    | Freischaltung JE PERSON in der Nabe
    | (`hasPluginCapability('calendar')`). Wer sie nicht hat, sieht nichts
    | ausser einem leisen Hinweis — niemandem wird etwas aufgedrueckt, nur weil
    | das Produkt die Quelle anbietet.
    |
    | `enabled => false` schaltet sie fuer ein ganzes Produkt ab. Dann
    | verschwindet auch der Hinweis: Wer eine Quelle nicht einschalten kann,
    | muss nicht erfahren, dass es sie gibt.
    |
    | Braucht `peppermint/ai-brain-bridge` (suggest, Regel 7). Fehlt die
    | Bruecke, ist die Quelle still nicht verfuegbar und meldet sich erst, wenn
    | jemand sie ausdruecklich benutzt — siehe
    | {@see \Peppermint\Calendar\Exceptions\BrainBridgeMissing}.
    */
    'brain_private_events' => [
        'enabled' => filter_var(env('CALENDAR_BRAIN_PRIVATE_EVENTS', true), FILTER_VALIDATE_BOOL),
        'label' => env('CALENDAR_BRAIN_PRIVATE_EVENTS_LABEL', 'AI Brain (privat)'),
        'colour' => env('CALENDAR_BRAIN_PRIVATE_EVENTS_COLOUR', '#8b5cf6'),

        /*
        | Die Wache, auf der die Person angemeldet ist.
        |
        | `null` heisst „die Vorgabe-Wache". Die Verwaltung fuehrt ihre
        | Menschen auf einer anderen — genau dieser Unterschied war bei
        | `BrainCalendarSources` schon einmal der Grund fuer eine dritte
        | Kopie, und er ist jetzt ein Parameter.
        */
        'guard' => env('CALENDAR_BRAIN_PRIVATE_EVENTS_GUARD'),
    ],

    /*
    | Table names, for adopting tables that already exist under other names.
    */
    'tables' => [
        'events' => 'calendar_events',
        'attendees' => 'calendar_event_attendees',
        'categories' => 'calendar_categories',
        'event_categories' => 'calendar_event_categories',

        // Der Vorlauf je Person. Die Tabelle kommt ueber eine
        // VEROEFFENTLICHTE Migration — wer Termin-Erinnerungen nie einschaltet,
        // soll keine leere Tabelle bekommen:
        //
        //     php artisan vendor:publish --tag=calendar-reminder-leads
        'reminder_preferences' => 'calendar_reminder_preferences',

        // Abonnierte fremde Kalender und ihre Termine. Ebenfalls ueber eine
        // veroeffentlichte Migration:
        //
        //     php artisan vendor:publish --tag=calendar-subscriptions
        //
        // Die Namen sind die aus dem Bestand des Peppermint Managers, von wo
        // die Abos am 29.10.2026 nach AI Brain gezogen sind — ohne Praefix,
        // weil sie dort die einzige Fassung sind und nicht „die vom Brain".
        'subscriptions' => 'external_calendars',
        'subscribed_events' => 'external_calendar_events',
    ],

    /*
    | Column names, for adopting a table that already exists.
    |
    | An application that has kept a calendar for years has its own names, and
    | several hundred references to them. Renaming those is a migration of the
    | codebase, not of the schema — so the package bends instead. Keys are what
    | the package calls a column, values are what your table calls it.
    |
    | Entries at this level apply to every table the package touches. Where two
    | tables disagree — `owner_id` exists on events AND on categories — nest
    | them under the table:
    |
    |     'columns' => [
    |         'owner_id'   => 'user_id',        // events
    |         'categories' => ['owner_id' => 'created_by'],
    |     ],
    |
    | The nested entry wins; the flat one stays the fallback, so existing
    | configurations keep working unchanged.
    */
    'columns' => [
        'uid' => 'uid',
        'kind' => 'kind',
        'owner_id' => 'owner_id',
        'subject_type' => 'subject_type',
        'subject_id' => 'subject_id',
        'starts_at' => 'starts_at',
        'ends_at' => 'ends_at',
        'all_day' => 'all_day',
        'visibility' => 'visibility',
    ],

    /*
    | Load the package's own migrations. Set to false in an application that
    | already owns a calendar_events table and brings the schema across itself;
    | publish them with the `calendar-migrations` tag to start from a copy.
    */
    /*
    |--------------------------------------------------------------------------
    | Event reminders
    |--------------------------------------------------------------------------
    |
    | A start time on its own tells nobody. These settings drive the
    | reconciliation that turns one into real reminder rows — the same rows a
    | person sets by hand, delivered down the same path, dismissible in the
    | same list. Needs `peppermint/reminders`; without it the reconciliation
    | refuses rather than half-working.
    |
    | `enabled` is false by default ON PURPOSE. Switching it on starts sending
    | notifications to real people; that is a product's decision, not a
    | package's, and a package that opts a product in by being installed is a
    | package that gets uninstalled.
    */
    'reminders' => [
        'enabled' => false,

        // How long before the start the reminder fires. The classic case, and
        // the only one: a reminder AT the start is a reminder about something
        // you are already late for.
        //
        // Die VORGABE des Produkts, nicht das letzte Wort: Wer etwas anderes
        // eingestellt hat, bekommt seinen Wert
        // ({@see \Peppermint\Calendar\Reminders\ReminderLead}). `null` ist
        // zulaessig und heisst „nur wer ausdruecklich eine will".
        'lead_minutes' => 15,

        // Die Stufen, die eine Einstellungsmaske anbietet — in Minuten.
        //
        // Nur die ZAHLEN, nicht die Worte: Ein Paket, das Beschriftungen
        // mitbringt, bringt eine Sprache mit, und das Produkt hat schon eine.
        // Dieselbe Trennung wie beim Schlummern in `peppermint/reminders`.
        //
        // „Keine" steht nicht in der Liste — das ist kein Vorlauf, sondern
        // seine Abwesenheit, und die gibt es immer.
        'lead_choices' => [5, 10, 15, 30, 60],

        // An all-day event starts at midnight, so "15 minutes before" would
        // land at 23:45 the night before — technically correct and useless.
        // It gets the morning of the day instead.
        'all_day_hour' => 8,

        // The zone that hour is meant in. The column stores UTC; without this
        // "08:00" would arrive two hours late in summer.
        'timezone' => 'Europe/Berlin',
    ],

    'run_migrations' => true,

    /*
    | Categories — an extra, not a core field.
    |
    | An application without categories has none: no column, no table, no empty
    | select. Wanting them takes three steps, on purpose:
    |
    |   1. vendor:publish --tag=calendar-categories
    |   2. run the published migration
    |   3. 'enabled' => true, and usesCategories() on the kinds that have them
    |
    | Where the link from an event to its category lives is the application's
    | business — the profile of its kind, or a column of its own adopted table.
    | The package owns the list, not the connection.
    */
    'categories' => [
        'enabled' => false,

        /*
        | How far the list may grow. See Peppermint\Calendar\Enums\CategoryMode.
        |
        |   closed   — only the managed list
        |   personal — everyone may extend it for themselves (default)
        |   open     — everyone may extend it for everyone
        |
        | "personal" answers both failure modes: a list nobody may touch gets
        | worked around, and a list everyone may extend for everyone fills up
        | with near-duplicates.
        */
        'mode' => 'personal',

        /*
        | Ability asked before a new category is created. The package knows the
        | name, the application knows what it means. null = not asked.
        */
        'create_ability' => null,

        /*
        | Ability für die Pflegemaske (CalendarRoutes::categories()).
        |
        | Same contract as `create_ability`: the package knows the NAME, not
        | the meaning. Unset means no check — a package inventing its own
        | permissions would be worse than one checking none. Who may maintain
        | a shared list is a question only the application can answer.
        */
        'manage_ability' => null,

        /*
        | Where the maintained list comes from — a callable, not a path.
        |
        | Maintained in one place (the AI Brain, because that is the hub); the
        | products pull with `calendar:pull-categories`. Each keeps its OWN
        | table: the package has to stay usable without a hub, and a calendar
        | that knows no categories while the hub is down is worse than one
        | that knows a slightly old list.
        |
        | Give it the NAME of an invokable class, not a closure: a closure in a
        | config file makes `config:cache` fail, and that runs on every
        | deployment.
        |
        |     'source' => \App\Kalender\KategorienAusDemBrain::class,
        |
        | A callable is still accepted — in tests and in a service provider it
        | is the more convenient form, and nothing is cached there.
        |
        | A fixed target here would have forced every consumer to have the
        | same hub. Unset means this system maintains its own list — not an
        | error, just a different arrangement.
        */
        'source' => null,

        /*
        | Seeded once by CategoryRegistry::seed(), idempotent by slug. Not read
        | afterwards: a label renamed in the database must not fall back on the
        | next boot.
        |
        | ['slug' => 'health', 'label' => 'Health', 'colour' => '#10b981']
        */
        'defaults' => [],
    ],

    /*
    |---------------------------------------------------------------------------
    | Feed (ICS subscription)
    |---------------------------------------------------------------------------
    |
    | The only unauthenticated route of this package: whoever holds the token
    | reads that calendar, from any calendar client. The product owns the token
    | — it decides the column, generates the value and lets people regenerate
    | it. The package only refuses anything shorter than 32 characters.
    */
    'feed' => [
        // Where the per-user token lives on the user model.
        'token_column' => env('CALENDAR_FEED_TOKEN_COLUMN', 'calendar_token'),

        // A subscription is not an archive: old events make the first fetch
        // slow for no gain. Forward reaches further, because people plan ahead.
        'past_days' => env('CALENDAR_FEED_PAST_DAYS', 90),
        'future_days' => env('CALENDAR_FEED_FUTURE_DAYS', 365),

        /*
        | Whether the feed also carries events from connected systems.
        |
        | Off for a spoke: those events live in the other system and are
        | subscribable there, and a feed that queries three remote systems on
        | every fetch is slow and fails with them.
        |
        | On for the HUB — the one system that aggregates the others (here: AI
        | Brain, which turns every connected product offering
        | `calendar.events.list` into a source). Its feed without the foreign
        | events would be a main calendar without its main content.
        */
        'include_sources' => env('CALENDAR_FEED_INCLUDE_SOURCES', false),
    ],

    'ics' => [
        /*
        | Identifies the software that produced the file. Clients show it when
        | something looks wrong, so name your application, not this package.
        */
        'prodid' => env('CALENDAR_ICS_PRODID', '-//Peppermint//Calendar//EN'),

        /*
        | Suffix for event UIDs. It must stay stable: change it and every
        | subscribed calendar treats the same events as new ones.
        */
        'uid_domain' => env('CALENDAR_ICS_UID_DOMAIN', 'calendar.local'),
    ],
];
