<?php

use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Recurrence\Occurrence;
use Peppermint\Calendar\Recurrence\OccurrenceExpander;

function storedEvent(array $attributes = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Jour Fixe',
        'starts_at' => '2026-09-07 09:00:00',
        'ends_at' => '2026-09-07 10:00:00',
    ], $attributes));
}

function appearances(string $from, string $to): array
{
    return app(OccurrenceExpander::class)
        ->expand(CalendarEvent::all(), new DateTimeImmutable($from), new DateTimeImmutable($to))
        ->map(fn (Occurrence $o) => $o->startsAt->format('Y-m-d H:i'))
        ->all();
}

it('leaves an event without a rule alone', function () {
    storedEvent();

    expect(appearances('2026-09-01', '2026-09-30'))->toBe(['2026-09-07 09:00']);
});

it('shows a weekly series on every one of its days', function () {
    // Ohne Ausrechnen stand eine wöchentliche Besprechung genau einmal im
    // Kalender — die Regel war gespeichert und wurde nie gelesen.
    storedEvent([
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
        'recurrence_until' => '2026-09-28',
    ]);

    expect(appearances('2026-09-01', '2026-09-30'))->toBe([
        '2026-09-07 09:00',
        '2026-09-14 09:00',
        '2026-09-21 09:00',
        '2026-09-28 09:00',
    ]);
});

it('keeps the length of each appearance', function () {
    storedEvent([
        'ends_at' => '2026-09-07 10:30:00',
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
        'recurrence_until' => '2026-09-14',
    ]);

    $occurrences = app(OccurrenceExpander::class)
        ->expand(CalendarEvent::all(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

    expect($occurrences->last()->endsAt->format('Y-m-d H:i'))->toBe('2026-09-14 10:30');
});

it('stops at the end of the series', function () {
    storedEvent([
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
        'recurrence_until' => '2026-09-14',
    ]);

    expect(appearances('2026-09-01', '2026-12-31'))->toBe(['2026-09-07 09:00', '2026-09-14 09:00']);
});

it('does not run forever when the series has no end', function () {
    // Eine unbefristete Regel darf nur bis zum Rand des Fensters rechnen —
    // sonst erzeugt sie im Jahr 2040 noch Termine.
    storedEvent(['recurrence_rules' => ['frequency' => 'daily']]);

    expect(appearances('2026-09-07', '2026-09-10'))->toHaveCount(3);
});

it('treats the end of the window as a moment, not as a whole day', function () {
    // Dieselbe Lesart wie scopeInRange. Wer den ganzen Tag meint, übergibt sein
    // Ende — zwei Lesarten in einem Paket sind der Weg, auf dem ein Termin in
    // genau einer Ansicht fehlt.
    storedEvent(['recurrence_rules' => ['frequency' => 'daily']]);

    expect(appearances('2026-09-07', '2026-09-10'))->toHaveCount(3)
        ->and(appearances('2026-09-07', '2026-09-10 23:59:59'))->toHaveCount(4);
});

it('marks which appearance is the original', function () {
    storedEvent([
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
        'recurrence_until' => '2026-09-14',
    ]);

    $occurrences = app(OccurrenceExpander::class)
        ->expand(CalendarEvent::all(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

    expect($occurrences->first()->isFirst)->toBeTrue()
        ->and($occurrences->last()->isFirst)->toBeFalse();
});

it('shows an event once when its rule is unreadable, instead of losing it', function () {
    // Der Eintrag ist echt, nur seine Wiederholung nicht lesbar. Ihn ganz
    // wegzulassen wäre der schlechtere Fehler: Er verschwindet aus dem
    // Kalender, ohne dass jemand erfährt warum.
    storedEvent(['recurrence_rules' => ['frequency' => 'stündlich']]);

    expect(appearances('2026-09-01', '2026-09-30'))->toBe(['2026-09-07 09:00']);
});

it('gives each appearance an identity of its own', function () {
    storedEvent([
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
        'recurrence_until' => '2026-09-14',
    ]);

    $keys = app(OccurrenceExpander::class)
        ->expand(CalendarEvent::all(), new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'))
        ->map(fn (Occurrence $o) => $o->key())
        ->all();

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1]);
});
