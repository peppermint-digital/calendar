<?php

use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Reminders\ReminderLead;
use Peppermint\Calendar\Subscriptions\Models\CalendarSubscription;
use Peppermint\Calendar\Subscriptions\SubscribedEventReminders;
use Peppermint\Reminders\Abgleich\Abgleich;
use Peppermint\Reminders\Models\Reminder;

/*
| Erinnerungen fuer Termine aus abonnierten fremden Kalendern (Bug #1081).
|
| Chris bekommt fuer Einladungen aus Teams, Google Meet oder einem anderen
| fremden System keine Erinnerung, obwohl der Termin angezeigt wird.
|
| Die Zahl, die diese Reihe wichtig macht: Am 29.10.2026 standen dem eigenen
| Kalender des Managers 21 kommende Termine gegenueber, dem Outlook-Abo von
| Chris 1009. Das Abo IST der Kalender, den er benutzt.
*/

beforeEach(function () {
    config()->set('calendar.reminders.enabled', true);
    config()->set('calendar.reminders.lead_minutes', 15);
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');
});

function aboVon(int $person, array $felder = []): CalendarSubscription
{
    return CalendarSubscription::create(array_merge([
        'user_id' => $person,
        'name' => 'Outlook',
        'url' => 'https://outlook.example.test/feed.ics',
        'is_active' => true,
    ], $felder));
}

function aboTermin(CalendarSubscription $abo, array $felder = []): object
{
    return $abo->events()->create(array_merge([
        'uid' => 'u-'.uniqid(),
        'source_uid' => 'q-'.uniqid(),
        'title' => 'Kundentermin ueber Teams',
        'start_datetime' => now()->addDay()->setTime(11, 0),
        'end_datetime' => now()->addDay()->setTime(12, 0),
        'all_day' => false,
    ], $felder));
}

function aboAbgleich(): array
{
    return (new Abgleich(app(SubscribedEventReminders::class)))->fahren();
}

it('erinnert den Menschen, der den Kalender abonniert hat', function () {
    $abo = aboVon(7);
    $termin = aboTermin($abo);

    expect(aboAbgleich()['angelegt'])->toBe(1);

    $erinnerung = Reminder::query()->first();

    expect((int) $erinnerung->user_id)->toBe(7);
    expect($erinnerung->source)->toBe(SubscribedEventReminders::SOURCE_LEAD);
    expect($erinnerung->title)->toBe('Kundentermin ueber Teams');
    expect($erinnerung->remind_at->equalTo($termin->start_datetime->copy()->subMinutes(15)))->toBeTrue();
});

it('nimmt denselben Vorlauf, den die Person fuer ihre Termine gewaehlt hat', function () {
    // Ein Mensch, der „30 Minuten vorher" gewaehlt hat, meint das fuer seine
    // Termine — nicht fuer die eine Haelfte davon.
    app(ReminderLead::class)->setzen(7, 30);
    $termin = aboTermin(aboVon(7));

    aboAbgleich();

    expect(Reminder::query()->first()->remind_at->equalTo($termin->start_datetime->copy()->subMinutes(30)))->toBeTrue();
});

it('erinnert gar nicht, wer „keine" gewaehlt hat', function () {
    $abo = aboVon(7);
    aboTermin($abo);
    expect(aboAbgleich()['angelegt'])->toBe(1);

    app(ReminderLead::class)->setzen(7, null);

    expect(aboAbgleich()['weggenommen'])->toBe(1);
    expect(Reminder::count())->toBe(0);
});

it('laesst ein abgeschaltetes Abo heraus und raeumt seine Zeilen weg', function () {
    $abo = aboVon(7);
    aboTermin($abo);
    aboAbgleich();
    expect(Reminder::count())->toBe(1);

    $abo->update(['is_active' => false]);

    expect(aboAbgleich()['weggenommen'])->toBe(1);
    expect(Reminder::count())->toBe(0);
});

it('gibt einem ganztaegigen Abo-Termin den Morgen in Ortszeit', function () {
    config()->set('calendar.reminders.all_day_hour', 8);
    config()->set('calendar.reminders.timezone', 'Europe/Berlin');

    aboTermin(aboVon(7), [
        'all_day' => true,
        'start_datetime' => now()->addDays(3)->startOfDay(),
        'end_datetime' => now()->addDays(3)->endOfDay(),
    ]);

    aboAbgleich();

    expect(Reminder::query()->first()->fresh()->remind_at->format('H:i'))->toBe('08:00');
});

/* ------------------------------------------------- die Doppelungsregel */

it('erinnert NICHT, wenn dieselbe Person den Termin auch als eigenen hat (gleiche Kennung)', function () {
    /*
     * Ein Termin, der im eigenen System angelegt wird, erscheint auch im
     * Outlook der Eingeladenen und damit im Abo. Ohne Regel waeren das zwei
     * Meldungen fuer eine Besprechung — und zwar bei JEDEM internen Termin.
     *
     * Entschieden am 29.10.2026: gleiche Quell-Kennung ODER gleicher Titel bei
     * gleicher Startzeit (± 1 min). Der eigene gewinnt, weil nur er
     * Teilnehmer, Zusage und ein Sprungziel hat.
     */
    $start = now()->addDay()->setTime(9, 0);

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 7,
        'uid' => 'gemeinsame-kennung',
        'title' => 'Jour Fixe',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHour(),
    ]);

    aboTermin(aboVon(7), [
        'source_uid' => 'gemeinsame-kennung',
        'title' => 'Ganz anders geschrieben',
        'start_datetime' => $start->copy()->addHours(5),
    ]);

    expect(aboAbgleich()['angelegt'])->toBe(0);
    expect(Reminder::count())->toBe(0);
});

it('erinnert NICHT bei gleichem Titel und gleicher Startzeit', function () {
    // Der Fall, der in der Praxis greift: Outlook vergibt seine eigene
    // Kennung, der Titel und die Uhrzeit sind aber dieselben.
    $start = now()->addDay()->setTime(9, 0);

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 7,
        'title' => 'Jour Fixe',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHour(),
    ]);

    aboTermin(aboVon(7), ['title' => 'Jour Fixe', 'start_datetime' => $start->copy()->addSeconds(40)]);

    expect(aboAbgleich()['angelegt'])->toBe(0);
});

it('erinnert trotzdem, wenn der Start mehr als eine Minute auseinanderliegt', function () {
    // Die Gegenprobe nach unten: Ohne sie koennte die Regel alles schlucken,
    // was denselben Titel traegt — und ein wiederkehrender „Jour Fixe" traegt
    // ihn jede Woche.
    $start = now()->addDay()->setTime(9, 0);

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 7,
        'title' => 'Jour Fixe',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHour(),
    ]);

    aboTermin(aboVon(7), ['title' => 'Jour Fixe', 'start_datetime' => $start->copy()->addMinutes(5)]);

    expect(aboAbgleich()['angelegt'])->toBe(1);
});

it('erinnert trotzdem, wenn der eigene Termin einem ANDEREN Menschen gehoert', function () {
    // Die Doppelung entsteht je Person. Volkers Termin ist fuer Chris keine.
    $start = now()->addDay()->setTime(9, 0);

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 99,
        'title' => 'Jour Fixe',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHour(),
    ]);

    aboTermin(aboVon(7), ['title' => 'Jour Fixe', 'start_datetime' => $start]);

    expect(aboAbgleich()['angelegt'])->toBe(1);
});

it('erkennt die Doppelung auch bei einem TEILNEHMER, nicht nur beim Eigner', function () {
    // Chris sieht einen Termin, den Volker angelegt hat, in seinem Outlook —
    // die Doppelung entsteht also bei ihm und nicht beim Eigner.
    $start = now()->addDay()->setTime(9, 0);

    $eigener = CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 99,
        'title' => 'Jour Fixe',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHour(),
    ]);
    $eigener->attendees()->create(['user_id' => 7, 'email' => 'chris@example.test']);

    aboTermin(aboVon(7), ['title' => 'Jour Fixe', 'start_datetime' => $start]);

    expect(aboAbgleich()['angelegt'])->toBe(0);
});

it('laesst sich von Outlooks Antwort-Praefix nicht aufhalten', function () {
    // Outlook haengt „Zugesagt: " an den Titel. Eine Doppelungsregel, die an
    // einem Doppelpunkt scheitert, ist keine.
    $start = now()->addDay()->setTime(9, 0);

    CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 7,
        'title' => 'Jour Fixe',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHour(),
    ]);

    aboTermin(aboVon(7), ['title' => 'Zugesagt: Jour  Fixe', 'start_datetime' => $start]);

    expect(aboAbgleich()['angelegt'])->toBe(0);
});
