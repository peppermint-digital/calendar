<?php

use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Reminders\EventReminders;
use Peppermint\Reminders\Models\Reminder;

/*
| Termin-Erinnerungen (#8747).
|
| Der Zeitpunkt ist ABSOLUT, eine Regel erzeugt ihn — entschieden am
| 17.09.2026 (AI Brain #5808, Idee #437) und hier nicht neu verhandelt:
| „Eine Art Zeile, ein Zustellweg. Mit beiden Arten müsste jede Abfrage zwei
| Fälle kennen." Deshalb prüft diese Reihe nicht eine Formel, sondern dass die
| Regel ihre Zeilen anlegt, mitführt und wieder wegnimmt.
*/

beforeEach(function () {
    config()->set('calendar.reminders.enabled', true);
    config()->set('calendar.reminders.lead_minutes', 15);
});

function erinnerungsTermin(array $felder = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 7,
        'title' => 'Jour Fixe',
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(10, 0),
    ], $felder));
}

function erinnerungsAbgleich(): array
{
    return app(EventReminders::class)->reconcile();
}

it('tut nichts, solange es abgeschaltet ist', function () {
    // Die Vorgabe. Ein Paket, das ein Produkt durch Installation anmeldet,
    // ist ein Paket, das deinstalliert wird.
    config()->set('calendar.reminders.enabled', false);
    erinnerungsTermin();

    expect(erinnerungsAbgleich()['created'])->toBe(0);
    expect(Reminder::count())->toBe(0);
});

it('legt die Erinnerung vor den Start', function () {
    $t = erinnerungsTermin();

    expect(erinnerungsAbgleich()['created'])->toBe(1);

    $e = Reminder::query()->first();

    expect($e->remind_at->equalTo($t->starts_at->copy()->subMinutes(15)))->toBeTrue();
    expect($e->source)->toBe(EventReminders::SOURCE_LEAD);
    expect((int) $e->user_id)->toBe(7);
    expect($e->title)->toBe('Jour Fixe');
});

it('haengt sie an den Termin, mit der Klasse des PRODUKTS', function () {
    // Stuende dort die Paket-Klasse, traefe die Suche nach den Erinnerungen
    // eines Termins nie — lautlos.
    $t = erinnerungsTermin();
    erinnerungsAbgleich();

    expect(Reminder::query()->forSubject($t)->count())->toBe(1);
    expect(Reminder::query()->first()->subject_type)->toBe(config('calendar.event_model'));
});

it('gibt einem Ganztagstermin den MORGEN, nicht Mitternacht minus 15', function () {
    // „15 Minuten vorher" waere 23:45 am Vortag — richtig gerechnet und
    // nutzlos.
    config()->set('calendar.reminders.timezone', 'Europe/Berlin');
    config()->set('calendar.reminders.all_day_hour', 8);

    $t = erinnerungsTermin([
        'all_day' => true,
        'starts_at' => now()->addDays(3)->startOfDay(),
        'ends_at' => now()->addDays(3)->endOfDay(),
    ]);

    erinnerungsAbgleich();

    $e = Reminder::query()->first();

    // 08:00 ORTSZEIT, nicht UTC: Die Spalte speichert UTC, und ohne die Zone
    // kaeme es im Sommer zwei Stunden zu spaet.
    expect($e->remind_at->setTimezone('Europe/Berlin')->format('H:i'))->toBe('08:00');
    expect($e->remind_at->setTimezone('Europe/Berlin')->toDateString())
        ->toBe($t->starts_at->setTimezone('Europe/Berlin')->toDateString());
});

it('nimmt die Erinnerung mit, wenn der Termin verschoben wird', function () {
    $t = erinnerungsTermin();
    erinnerungsAbgleich();

    $t->update(['starts_at' => now()->addDays(2)->setTime(14, 0)]);

    expect(erinnerungsAbgleich()['moved'])->toBe(1);

    $e = Reminder::query()->first();
    expect($e->remind_at->equalTo($t->fresh()->starts_at->copy()->subMinutes(15)))->toBeTrue();
    expect(Reminder::count())->toBe(1);
});

it('setzt beim Verschieben die Zustellung zurueck', function () {
    // Was zum alten Zeitpunkt herausging, gilt nicht mehr. Ohne das
    // Zuruecksetzen erreicht die verschobene Erinnerung niemanden.
    $t = erinnerungsTermin();
    erinnerungsAbgleich();
    Reminder::query()->first()->markDelivered();

    $t->update(['starts_at' => now()->addDays(2)->setTime(14, 0)]);
    erinnerungsAbgleich();

    expect(Reminder::query()->first()->delivered_at)->toBeNull();
});

it('legt beim zweiten Durchgang nichts doppelt an', function () {
    erinnerungsTermin();

    expect(erinnerungsAbgleich()['created'])->toBe(1);
    expect(erinnerungsAbgleich()['created'])->toBe(0);
    expect(Reminder::count())->toBe(1);
});

it('raeumt weg, was keinen Termin mehr hat', function () {
    // Der polymorphe Verweis traegt keinen Fremdschluessel, die Datenbank
    // kaskadiert also nicht. Das Aufraeumen gehoert dem Besitzer.
    $t = erinnerungsTermin();
    erinnerungsAbgleich();

    $t->delete();

    expect(erinnerungsAbgleich()['removed'])->toBe(1);
    expect(Reminder::count())->toBe(0);
});

it('hoert auf, sobald der Termin begonnen hat', function () {
    $t = erinnerungsTermin();
    erinnerungsAbgleich();

    $t->update([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->subMinutes(30),
    ]);

    expect(erinnerungsAbgleich()['removed'])->toBe(1);
    expect(Reminder::count())->toBe(0);
});

it('laesst eine abgehakte Zeile stehen, auch wenn ihr Anlass entfaellt', function () {
    // Sie ist der Nachweis, dass jemand erinnert wurde und gehandelt hat.
    $t = erinnerungsTermin();
    erinnerungsAbgleich();
    Reminder::query()->first()->dismiss();

    $t->delete();
    erinnerungsAbgleich();

    expect(Reminder::count())->toBe(1);
    expect(Reminder::query()->first()->is_dismissed)->toBeTrue();
});

it('fasst eine abgehakte Zeile auch beim Verschieben nicht an', function () {
    $t = erinnerungsTermin();
    erinnerungsAbgleich();
    $e = Reminder::query()->first();
    $e->dismiss();
    $vorher = $e->fresh()->remind_at;

    $t->update(['starts_at' => now()->addDays(5)->setTime(11, 0)]);
    erinnerungsAbgleich();

    expect(Reminder::query()->first()->remind_at->equalTo($vorher))->toBeTrue();
});

it('zaehlt Termine ohne Empfaenger, statt sie still zu uebergehen', function () {
    erinnerungsTermin(['owner_id' => null]);

    $bilanz = erinnerungsAbgleich();

    expect($bilanz['skipped'])->toBe(1);
    expect($bilanz['created'])->toBe(0);
    expect(Reminder::count())->toBe(0);
});

it('nimmt den Empfaenger, den das Produkt bestimmt', function () {
    // „Alle Teilnehmer ausser dem Eigner" ist eine Frage an produkteigene
    // Beziehungen — der Kern nimmt eine Antwort an, er ermittelt sie nicht.
    erinnerungsTermin(['owner_id' => 7]);

    $bilanz = (new EventReminders(fn (CalendarEvent $t) => 99))->reconcile();

    expect($bilanz['created'])->toBe(1);
    expect((int) Reminder::query()->first()->user_id)->toBe(99);
});
