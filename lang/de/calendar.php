<?php

return [
    'kind' => [
        'unknown' => 'Terminart [:given] ist nicht registriert. Bekannt: :known. Arten werden in config/calendar.php unter "kinds" eingetragen oder über EventKindRegistry::register() angemeldet — das Paket bringt selbst keine mit.',
        'none_registered' => 'keine registriert',
        'forbidden_attribute' => 'Feld [:attribute] ist für Terminart [:kind] nicht zulässig und wurde gesetzt. Entweder gehört der Termin zu einer anderen Art, oder das Feld gehört ins Profil dieser Art. Die Liste steht in der EventKind-Klasse unter forbiddenAttributes().',
    ],

    'frequency' => [
        'daily' => 'Täglich',
        'weekly' => 'Wöchentlich',
        'biweekly' => 'Alle zwei Wochen',
        'monthly' => 'Monatlich',
    ],

    'weekday_short' => [
        'MO' => 'Mo', 'TU' => 'Di', 'WE' => 'Mi', 'TH' => 'Do',
        'FR' => 'Fr', 'SA' => 'Sa', 'SU' => 'So',
    ],

    'recurrence' => [
        'until' => 'bis :date',
        'invalid_frequency' => 'Unbekannte Wiederholung [:given]. Erwartet: daily, weekly, biweekly oder monthly.',
        'missing_weekdays' => 'Eine wöchentliche Serie braucht mindestens einen Wochentag.',
        'invalid_weekday' => 'Unbekannter Wochentag [:given]. Erwartet: MO, TU, WE, TH, FR, SA oder SU.',
        'invalid_month_day' => 'Monatstag [:given] liegt außerhalb des Bereichs. Erwartet: 1 bis 31.',
    ],
];
