<?php

use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Reminders\EventReminders;
use Peppermint\Calendar\Reminders\LeadPreference;
use Peppermint\Calendar\Reminders\ReminderLead;
use Peppermint\Reminders\Models\Reminder;

/*
| Wer erinnert wird, und wann — je Person (Bug #1080 im Peppermint Manager).
|
| Zwei Dinge in einer Reihe, weil sie dieselbe Ursache haben: Bis zum
| 29.10.2026 erinnerte diese Regel GENAU EINE Person, den Eigner. Chris ist bei
| seinen Terminen Teilnehmer — er haette auch mit eingeschaltetem Schalter nie
| etwas bekommen, und der Lauf haette dabei „angelegt" gemeldet.
|
| Daraus folgte beides: mehrere Empfaenger, und ein Vorlauf je Person (derselbe
| Termin hat dann nicht einen Zeitpunkt, sondern einen je Mensch).
*/

beforeEach(function () {
    config()->set('calendar.reminders.enabled', true);
    config()->set('calendar.reminders.lead_minutes', 15);
    config()->set('calendar.reminders.lead_choices', [5, 10, 15, 30, 60]);
});

function vorlaufTermin(array $felder = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Besprechung',
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(10, 0),
    ], $felder));
}

function vorlaufAbgleich(): array
{
    return app(EventReminders::class)->reconcile();
}

it('erinnert den Eigner UND die internen Teilnehmer', function () {
    // Der Kern von Bug #1080. Vorher bekam nur die 1 eine Zeile.
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);
    $t->attendees()->create(['user_id' => 3, 'email' => 'anna@example.test']);

    expect(vorlaufAbgleich()['created'])->toBe(3);

    expect(Reminder::query()->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([1, 2, 3]);
});

it('laesst externe Teilnehmer aus — es gibt dort keine Glocke', function () {
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => null, 'email' => 'gast@example.test', 'name' => 'Gast']);

    expect(vorlaufAbgleich()['created'])->toBe(1);
    expect((int) Reminder::query()->first()->user_id)->toBe(1);
});

it('erinnert nicht, wer abgesagt hat', function () {
    // Wer abgesagt hat, hat gesagt, dass er nicht kommt. `pending` und
    // `tentative` bleiben drin: dort ist die Frage offen.
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'nein@example.test', 'status' => 'declined']);
    $t->attendees()->create(['user_id' => 3, 'email' => 'vielleicht@example.test', 'status' => 'tentative']);

    vorlaufAbgleich();

    expect(Reminder::query()->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([1, 3]);
});

it('zaehlt den Eigner nicht zweimal, wenn er auch Teilnehmer ist', function () {
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 1, 'email' => 'ich@example.test']);

    expect(vorlaufAbgleich()['created'])->toBe(1);
});

it('gibt jeder Person ihren EIGENEN Vorlauf', function () {
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);

    app(ReminderLead::class)->setzen(2, 30);

    vorlaufAbgleich();

    $start = $t->fresh()->starts_at;

    expect(Reminder::query()->forUser(1)->first()->remind_at->equalTo($start->copy()->subMinutes(15)))->toBeTrue();
    expect(Reminder::query()->forUser(2)->first()->remind_at->equalTo($start->copy()->subMinutes(30)))->toBeTrue();
});

it('erinnert gar nicht, wer „keine" gewaehlt hat', function () {
    // „Keine" braucht kein zweites Feld: Die Person fehlt in der Antwort, und
    // der Abgleich raeumt ihre Zeile weg.
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);

    expect(vorlaufAbgleich()['created'])->toBe(2);

    app(ReminderLead::class)->setzen(2, null);

    expect(vorlaufAbgleich()['removed'])->toBe(1);
    expect(Reminder::query()->forUser(2)->count())->toBe(0);
    expect(Reminder::query()->forUser(1)->count())->toBe(1);
});

it('nimmt der Person die Zeile weg, die kein Teilnehmer mehr ist', function () {
    $t = vorlaufTermin(['owner_id' => 1]);
    $teilnahme = $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);

    vorlaufAbgleich();
    expect(Reminder::count())->toBe(2);

    $teilnahme->delete();

    expect(vorlaufAbgleich()['removed'])->toBe(1);
    expect(Reminder::query()->forUser(1)->count())->toBe(1);
    expect(Reminder::query()->forUser(2)->count())->toBe(0);
});

it('verschiebt beim Umlegen des Termins JEDE Zeile, mit dem Vorlauf ihrer Person', function () {
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);
    app(ReminderLead::class)->setzen(2, 60);

    vorlaufAbgleich();

    $t->update(['starts_at' => now()->addDays(2)->setTime(14, 0)]);

    expect(vorlaufAbgleich()['moved'])->toBe(2);

    $start = $t->fresh()->starts_at;

    expect(Reminder::query()->forUser(1)->first()->remind_at->equalTo($start->copy()->subMinutes(15)))->toBeTrue();
    expect(Reminder::query()->forUser(2)->first()->remind_at->equalTo($start->copy()->subMinutes(60)))->toBeTrue();
});

it('gibt einem Ganztagstermin fuer JEDE Person den Morgen — der Vorlauf gilt dort nicht', function () {
    // Es gibt keine Uhrzeit, von der 30 Minuten abgezogen werden koennten.
    // Was je Person bleibt, ist die Frage, OB erinnert wird.
    config()->set('calendar.reminders.timezone', 'Europe/Berlin');
    config()->set('calendar.reminders.all_day_hour', 8);

    $t = vorlaufTermin([
        'all_day' => true,
        'starts_at' => now()->addDays(3)->startOfDay(),
        'ends_at' => now()->addDays(3)->endOfDay(),
    ]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);
    app(ReminderLead::class)->setzen(2, 60);

    vorlaufAbgleich();

    foreach ([1, 2] as $person) {
        expect(Reminder::query()->forUser($person)->first()->remind_at->setTimezone('Europe/Berlin')->format('H:i'))
            ->toBe('08:00');
    }
});

it('faellt ohne die Einstellungstabelle auf die Vorgabe zurueck, statt abzubrechen', function () {
    // Die Migration wird nur VEROEFFENTLICHT — in einem Produkt kann die
    // Tabelle ganz fehlen. Dann gilt fuer alle die Vorgabe: weniger, als
    // jemand eingestellt hat, aber nicht nichts.
    Schema::drop((new LeadPreference)->getTable());

    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);

    expect(vorlaufAbgleich()['created'])->toBe(2);

    $start = $t->fresh()->starts_at;
    expect(Reminder::query()->forUser(2)->first()->remind_at->equalTo($start->copy()->subMinutes(15)))->toBeTrue();
});

it('meldet einen Termin ohne Beteiligte, aber nicht einen, bei dem alle abgestellt haben', function () {
    // Der Unterschied, den nur diese Klasse kennt: Das erste ist ein Befund,
    // das zweite eine Entscheidung. Wuerde der Lauf beides melden, warnte der
    // Zeitplan dauerhaft ueber eine bewusste Einstellung.
    vorlaufTermin(['owner_id' => null, 'title' => 'Niemandes Termin']);

    $mitLeuten = vorlaufTermin(['owner_id' => 5, 'title' => 'Alle abgestellt']);
    app(ReminderLead::class)->setzen(5, null);

    $bilanz = vorlaufAbgleich();

    expect($bilanz['skipped'])->toBe(1);
    expect($bilanz['created'])->toBe(0);
    expect($mitLeuten->fresh())->not->toBeNull();
});

it('nimmt weiter die Antwort des Produkts, wenn es eine gibt', function () {
    // Was die alte Entscheidung schuetzen wollte, hat weiter einen Weg — und
    // jetzt auch fuer mehrere Menschen auf einmal.
    $t = vorlaufTermin(['owner_id' => 1]);
    $t->attendees()->create(['user_id' => 2, 'email' => 'chris@example.test']);

    $bilanz = (new EventReminders(fn (CalendarEvent $termin) => [98, 99]))->reconcile();

    expect($bilanz['created'])->toBe(2);
    expect(Reminder::query()->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([98, 99]);
});
