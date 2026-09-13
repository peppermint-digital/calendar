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
    | Table names, for adopting tables that already exist under other names.
    */
    'tables' => [
        'events' => 'calendar_events',
        'attendees' => 'calendar_event_attendees',
        'categories' => 'calendar_categories',
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
        | Seeded once by CategoryRegistry::seed(), idempotent by slug. Not read
        | afterwards: a label renamed in the database must not fall back on the
        | next boot.
        |
        | ['slug' => 'health', 'label' => 'Health', 'colour' => '#10b981']
        */
        'defaults' => [],
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
