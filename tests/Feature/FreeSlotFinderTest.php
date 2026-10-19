<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Scheduling\FreeSlotFinder;

/**
 * Die Terminfindung: wann sind alle frei? (#7846)
 *
 * Sie gab es am 16.10.2026 nur im Manager. Die Rechnung ist in allen vier
 * Systemen dieselbe — nur die Frage „wer ist beschäftigt" ist produkteigen.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-07 08:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function slotTermin(int $owner, string $von, string $bis, array $werte = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => $owner,
        'title' => 'Besetzt',
        'starts_at' => $von,
        'ends_at' => $bis,
    ], $werte));
}

/** Die Scheibe, die an einem Tag zu einer Uhrzeit beginnt. */
function scheibe(array $raster, string $datum, string $zeit): array
{
    $tag = collect($raster)->firstWhere('date', $datum);

    return collect($tag['slots'])->firstWhere('start', $zeit);
}

it('schneidet das Arbeitsfenster in Scheiben', function () {
    // Montag, 9 bis 11 Uhr, halbstuendlich = vier Scheiben.
    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 11, slotMinutes: 30,
    );

    expect($raster)->toHaveCount(1)
        ->and($raster[0]['slots'])->toHaveCount(4)
        ->and($raster[0]['slots'][0]['start'])->toBe('09:00')
        ->and($raster[0]['slots'][3]['end'])->toBe('11:00');
});

it('nennt eine Scheibe besetzt, in der jemand etwas vorhat', function () {
    slotTermin(1, '2026-09-07 09:30', '2026-09-07 10:00');

    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 11,
    );

    expect(scheibe($raster, '2026-09-07', '09:30')['free'])->toBeFalse()
        ->and(scheibe($raster, '2026-09-07', '09:00')['free'])->toBeTrue();
});

/**
 * Angrenzend ist NICHT überlappend. Wer das verwechselt, baut einen Planer,
 * der jede zweite Scheibe ohne sichtbaren Grund ablehnt.
 */
it('laesst eine angrenzende Scheibe frei', function () {
    slotTermin(1, '2026-09-07 09:00', '2026-09-07 10:00');

    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 11, slotMinutes: 60,
    );

    expect(scheibe($raster, '2026-09-07', '09:00')['free'])->toBeFalse()
        ->and(scheibe($raster, '2026-09-07', '10:00')['free'])->toBeTrue();
});

/**
 * Ganztägig belegt den ganzen Tag: Es gibt keine Scheibe, in die der Termin
 * fällt — ohne Sonderbehandlung wäre jemand mit einem Urlaubstag den ganzen
 * Tag „frei".
 */
it('belegt bei ganztaegig den ganzen Tag', function () {
    slotTermin(1, '2026-09-07 00:00', '2026-09-07 23:59', ['all_day' => true]);

    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 11,
    );

    expect(collect($raster[0]['slots'])->pluck('free')->unique()->all())->toBe([false]);
});

/**
 * Wer eingeladen ist, hat dann keine Zeit — auch wenn der Termin jemand
 * anderem gehört. Ohne das schlägt die Terminfindung genau die Stunde vor, in
 * der die halbe Runde schon in einer anderen Besprechung sitzt.
 */
it('rechnet eine Einladung als Belegung', function () {
    $termin = slotTermin(2, '2026-09-07 09:00', '2026-09-07 10:00');
    $termin->attendees()->create(['user_id' => 1, 'status' => 'accepted']);

    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 11, slotMinutes: 60,
    );

    expect(scheibe($raster, '2026-09-07', '09:00')['free'])->toBeFalse();
});

it('sagt, WER besetzt ist, nicht nur dass jemand besetzt ist', function () {
    slotTermin(2, '2026-09-07 09:00', '2026-09-07 10:00');

    $raster = app(FreeSlotFinder::class)->grid(
        [1, 2], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 10, slotMinutes: 60,
    );

    $erste = scheibe($raster, '2026-09-07', '09:00');

    expect($erste['busyUsers'])->toBe([2])
        ->and($erste['busyCount'])->toBe(1)
        ->and($erste['total'])->toBe(2);
});

/**
 * Produkteigene Belegungen kommen HEREIN, statt erraten zu werden: Der Manager
 * rechnet Urlaub und Aufgabengruppen mit, die Verwaltung Zeitblöcke.
 */
it('nimmt fremde Belegungen des Produkts an', function () {
    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 11, slotMinutes: 60,
        extra: [[
            'user' => 1,
            'start' => CarbonImmutable::parse('2026-09-07 09:00'),
            'end' => CarbonImmutable::parse('2026-09-07 10:00'),
        ]],
    );

    expect(scheibe($raster, '2026-09-07', '09:00')['free'])->toBeFalse();
});

/**
 * Am Wochenende ist niemand „frei" — er hat nur nichts vor. Sonst schlägt der
 * Planer Sonntagmorgen als beste Zeit vor.
 */
it('nennt das Wochenende nicht frei, sagt aber dass es eines ist', function () {
    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-12'), CarbonImmutable::parse('2026-09-12'),
        workingHoursStart: 9, workingHoursEnd: 11,
    );

    expect($raster[0]['weekend'])->toBeTrue()
        ->and(collect($raster[0]['slots'])->pluck('free')->unique()->all())->toBe([false]);
});

it('laesst das Wochenende auf Wunsch mitzaehlen', function () {
    $raster = app(FreeSlotFinder::class)->grid(
        [1], CarbonImmutable::parse('2026-09-12'), CarbonImmutable::parse('2026-09-12'),
        workingHoursStart: 9, workingHoursEnd: 11, includeWeekends: true,
    );

    expect(scheibe($raster, '2026-09-12', '09:00')['free'])->toBeTrue();
});

it('kommt ohne Personen aus, ohne zu werfen', function () {
    $raster = app(FreeSlotFinder::class)->grid(
        [], CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-07'),
        workingHoursStart: 9, workingHoursEnd: 10,
    );

    expect($raster[0]['slots'][0]['total'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Serien (#8307)
|--------------------------------------------------------------------------
|
| Bis zum 19.10.2026 nahm der Finder `starts_at` direkt. Eine woechentliche
| Besprechung blockierte damit genau eine Stunde statt jeder Woche — und eine
| Serie, die VOR dem Fenster beginnt, fand er ueberhaupt nicht.
|
| Das ist der schlimmste Fehler, den ein Planer machen kann: Er bietet eine
| Zeit an, in der die halbe Runde schon sitzt, und sieht dabei plausibel aus.
*/

it('belegt jede Woche einer woechentlichen Serie, nicht nur die erste', function () {
    slotTermin(1, '2026-09-07 10:00', '2026-09-07 11:00', [
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
    ]);

    $raster = app(FreeSlotFinder::class)->grid(
        [1],
        CarbonImmutable::parse('2026-09-07'),
        CarbonImmutable::parse('2026-09-21'),
    );

    // Der 07.09., 14.09. und 21.09.2026 sind alle Montage.
    expect(scheibe($raster, '2026-09-07', '10:00')['free'])->toBeFalse()
        ->and(scheibe($raster, '2026-09-14', '10:00')['free'])->toBeFalse()
        ->and(scheibe($raster, '2026-09-21', '10:00')['free'])->toBeFalse();
});

it('findet eine Serie, die vor dem Fenster beginnt', function () {
    // Beginnt zwei Wochen vor dem Fenster — mit `inRange` war sie unsichtbar.
    slotTermin(1, '2026-08-24 10:00', '2026-08-24 11:00', [
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
    ]);

    $raster = app(FreeSlotFinder::class)->grid(
        [1],
        CarbonImmutable::parse('2026-09-07'),
        CarbonImmutable::parse('2026-09-07'),
    );

    expect(scheibe($raster, '2026-09-07', '10:00')['free'])->toBeFalse();
});

it('laesst die Scheibe daneben frei, auch bei einer Serie', function () {
    slotTermin(1, '2026-09-07 10:00', '2026-09-07 11:00', [
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
    ]);

    $raster = app(FreeSlotFinder::class)->grid(
        [1],
        CarbonImmutable::parse('2026-09-07'),
        CarbonImmutable::parse('2026-09-14'),
    );

    // Eine Serie darf nicht den ganzen Tag sperren — nur ihre Stunde.
    expect(scheibe($raster, '2026-09-14', '11:00')['free'])->toBeTrue();
});

it('laesst einen Dienstag frei, wenn die Serie montags liegt', function () {
    slotTermin(1, '2026-09-07 10:00', '2026-09-07 11:00', [
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
    ]);

    $raster = app(FreeSlotFinder::class)->grid(
        [1],
        CarbonImmutable::parse('2026-09-07'),
        CarbonImmutable::parse('2026-09-08'),
    );

    expect(scheibe($raster, '2026-09-08', '10:00')['free'])->toBeTrue();
});
