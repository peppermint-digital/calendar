<?php

return [
    'kind' => [
        'unknown' => 'Event kind [:given] is not registered. Known kinds: :known. Register kinds in config/calendar.php under "kinds", or through EventKindRegistry::register() — this package ships none of its own.',
        'none_registered' => 'none registered',
        'forbidden_attribute' => 'Field [:attribute] is not allowed for event kind [:kind] but was set. Either the event belongs to a different kind, or the field belongs in that kind\'s profile. The list lives in the EventKind class under forbiddenAttributes().',
    ],

    'frequency' => [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'biweekly' => 'Every two weeks',
        'monthly' => 'Monthly',
    ],

    'weekday_short' => [
        'MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu',
        'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun',
    ],

    'recurrence' => [
        'until' => 'until :date',
        'invalid_frequency' => 'Unknown recurrence frequency [:given]. Expected daily, weekly, biweekly or monthly.',
        'missing_weekdays' => 'A weekly or biweekly series needs at least one weekday.',
        'invalid_weekday' => 'Unknown weekday [:given]. Expected MO, TU, WE, TH, FR, SA or SU.',
        'invalid_month_day' => 'Day of month [:given] is out of range. Expected 1 to 31.',
    ],
];
