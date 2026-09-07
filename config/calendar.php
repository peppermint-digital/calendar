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
