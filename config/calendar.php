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
    | Table names, for adopting tables that already exist under other names.
    */
    'tables' => [
        'events' => 'calendar_events',
        'attendees' => 'calendar_event_attendees',
    ],

    /*
    | Column names, for adopting a table that already exists.
    |
    | An application that has kept a calendar for years has its own names, and
    | several hundred references to them. Renaming those is a migration of the
    | codebase, not of the schema — so the package bends instead. Keys are what
    | the package calls a column, values are what your table calls it.
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
