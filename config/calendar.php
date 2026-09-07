<?php

return [
    /*
    | Nutzer-Modell der einbindenden Anwendung. Das Paket kennt keine eigene
    | Nutzerverwaltung — es hält nur eine Referenz.
    */
    'user_model' => env('CALENDAR_USER_MODEL', 'App\\Models\\User'),

    /*
    | Terminarten. Jede Anwendung registriert ihre eigenen; das Paket bringt
    | keine mit. Eintrag: FQCN einer Klasse, die Peppermint\Calendar\Kinds\EventKind
    | erweitert.
    */
    'kinds' => [],

    /*
    | Wie lange ein gelöschter Termin im Papierkorb liegt, wenn die Terminart
    | keine eigene Frist nennt. null = unbegrenzt.
    */
    'trash_retention_days' => 30,
];
