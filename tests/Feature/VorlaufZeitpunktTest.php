<?php

use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Reminders\EventReminders;
use Peppermint\Calendar\Reminders\LeadMoment;
use Peppermint\Reminders\Models\Reminder;

/*
| Die Rechnung „wann wird erinnert" — und die Zeitzone, an der sie haengen
| blieb.
|
| Bis zum 29.10.2026 endete der Ganztagsfall auf `->utc()`, mit der Begruendung
| „die Spalte speichert UTC". Das ist eine Annahme ueber das PRODUKT und kein
| Fakt: Im Peppermint Manager steht `app.timezone` auf `Europe/Berlin`.
|
| Eloquent formatiert beim Speichern in der Zeitzone des Carbon-Objekts und
| liest die Spalte in `app.timezone` zurueck. Ein als UTC geschriebenes
| „08:00 Berlin" landete als `07:00` in der Spalte und kam als 07:00 ORTSZEIT
| wieder heraus — die Ganztags-Erinnerung feuerte eine Stunde zu frueh, im
| Sommer zwei. Kein Fehler, kein roter Test; die Testreihe dieses Pakets lief
| selbst auf UTC und konnte es nicht sehen.
*/

beforeEach(function () {
    config()->set('calendar.reminders.enabled', true);
    config()->set('calendar.reminders.lead_minutes', 15);
    config()->set('calendar.reminders.all_day_hour', 8);
    config()->set('calendar.reminders.timezone', 'Europe/Berlin');
});

it('gibt einem Ganztagstermin acht Uhr ORTSZEIT, auch wenn die Anwendung in Ortszeit laeuft', function () {
    // Der Fall des Managers. Ohne die Korrektur stehen hier 07:00.
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    $moment = LeadMoment::fuer('2026-11-05 00:00:00', true, 15);

    expect($moment->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-11-05 08:00');
    // Und die Zeitzone, in der der Wert gespeichert wird, ist die der
    // Anwendung — genau die, in der die Spalte wieder gelesen wird.
    expect($moment->format('Y-m-d H:i'))->toBe('2026-11-05 08:00');
});

it('gibt ihm acht Uhr Ortszeit auch, wenn die Anwendung auf UTC laeuft', function () {
    // Der Fall der uebrigen Produkte. Hier war es schon richtig, und es bleibt
    // richtig — sonst waere die Korrektur nur eine Verschiebung des Fehlers.
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');

    $moment = LeadMoment::fuer('2026-11-05 00:00:00', true, 15);

    expect($moment->setTimezone('Europe/Berlin')->format('H:i'))->toBe('08:00');
    expect($moment->format('H:i'))->toBe('07:00');
});

it('rechnet im Sommer mit zwei Stunden, nicht mit einer', function () {
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    expect(LeadMoment::fuer('2026-07-15 00:00:00', true, 15)->setTimezone('Europe/Berlin')->format('H:i'))
        ->toBe('08:00');
});

it('zieht bei einem Termin mit Uhrzeit nur ab und rechnet NICHT um', function () {
    // Umrechnen wuerde denselben Fehler einfuehren: Der Beginn traegt schon
    // die Zeitzone, in der das Produkt seine Zeiten fuehrt.
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    $moment = LeadMoment::fuer('2026-11-05 09:00:00', false, 30);

    expect($moment->format('Y-m-d H:i'))->toBe('2026-11-05 08:30');
});

it('kommt bis in die Spalte und zurueck, ohne eine Stunde zu verlieren', function () {
    // Die Gegenprobe ueber den ECHTEN Weg: anlegen, aus der Datenbank lesen,
    // Uhrzeit nachrechnen. Die Rechnung allein zu pruefen haette den Fehler
    // nicht gefunden — er entstand erst beim Speichern und Zuruecklesen.
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Feiertag',
        'all_day' => true,
        'starts_at' => now()->addDays(4)->startOfDay(),
        'ends_at' => now()->addDays(4)->endOfDay(),
    ]);

    app(EventReminders::class)->reconcile();

    $gelesen = Reminder::query()->first()->fresh()->remind_at;

    expect($gelesen->format('H:i'))->toBe('08:00');
});
