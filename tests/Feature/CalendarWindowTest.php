<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Support\CalendarWindow;

beforeEach(function () {
    config()->set('app.timezone', 'Europe/Berlin');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('lets explicit dates win over a range word', function () {
    $window = CalendarWindow::resolve('month', '2026-09-01', '2026-09-03');

    expect($window->from->toDateTimeString())->toBe('2026-09-01 00:00:00')
        ->and($window->to->toDateTimeString())->toBe('2026-09-03 23:59:59');
});

it('ends a window at the end of a day, not at the start of the next', function () {
    // Ein Fenster, das um Mitternacht endet, laesst den letzten Tag aus — und
    // der Fehler faellt nur an Terminen am spaeten Abend auf.
    CarbonImmutable::setTestNow('2026-09-13 15:00:00');

    expect(CalendarWindow::resolve('today')->to->format('H:i:s'))->toBe('23:59:59');
});

it('means seven calendar days by "week"', function () {
    CarbonImmutable::setTestNow('2026-09-13 15:00:00');

    $window = CalendarWindow::resolve('week');

    expect($window->from->toDateString())->toBe('2026-09-13')
        ->and($window->to->toDateString())->toBe('2026-09-19')
        ->and($window->days())->toBe(7);
});

it('does not let a month overflow February', function () {
    // Mit ueberlaufendem addMonth wird aus dem 31.01. der 03.03. — und das
    // Fenster ist drei Tage zu lang, jedes Jahr, genau einmal.
    CarbonImmutable::setTestNow('2026-01-31 09:00:00');

    expect(CalendarWindow::resolve('month')->to->toDateString())->toBe('2026-02-28');
});

it('counts calendar days over a daylight saving change, not hours', function () {
    // Der 25.10.2026 hat in Europe/Berlin 25 Stunden. Wer Tage in Millisekunden
    // weiterzaehlt, landet am selben Tag zurueck und verliert einen.
    CarbonImmutable::setTestNow('2026-10-22 12:00:00');

    $window = CalendarWindow::resolve('week');

    expect($window->to->toDateString())->toBe('2026-10-28')
        ->and($window->days())->toBe(7);
});

it('reads a window in the application’s zone, not in UTC', function () {
    // Der Moment ist bewusst so gewaehlt, dass die beiden Zonen an
    // VERSCHIEDENEN Tagen stehen: 23:30 UTC ist in Berlin bereits der
    // Folgetag. Ohne diesen Abstand waere der Test in beiden Faellen gruen und
    // wuerde nichts beweisen — die erste Fassung war genau so, und die
    // Gegenprobe hat sie aufgedeckt.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-13 23:30:00', 'UTC'));

    expect(CarbonImmutable::now('UTC')->toDateString())->toBe('2026-09-13')
        ->and(CarbonImmutable::now('Europe/Berlin')->toDateString())->toBe('2026-09-14')
        ->and(CalendarWindow::resolve('today')->from->toDateString())->toBe('2026-09-14');
});

it('falls back to a week when the range word is unknown', function () {
    // Am anderen Ende sitzt oft ein Sprachmodell. Eine Woche auf einen
    // Tippfehler ist eine bessere Antwort als eine Ausnahme.
    CarbonImmutable::setTestNow('2026-09-13 15:00:00');

    expect(CalendarWindow::resolve('naechste-woche-bitte')->days())->toBe(7);
});
