<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Enums\MonthDayOverflow;
use Peppermint\Calendar\Exceptions\InvalidRecurrenceRule;
use Peppermint\Calendar\Recurrence\RecurrenceCalculator;
use Peppermint\Calendar\Recurrence\RecurrenceRule;

function expand(array $rules, string $start, string $until): array
{
    return array_map(
        fn (CarbonImmutable $date) => $date->format('Y-m-d H:i'),
        app(RecurrenceCalculator::class)->occurrences(
            CarbonImmutable::parse($start),
            RecurrenceRule::fromArray($rules),
            CarbonImmutable::parse($until),
        ),
    );
}

it('repeats daily and keeps the time of day', function () {
    expect(expand(['frequency' => 'daily'], '2026-09-07 09:30', '2026-09-10'))
        ->toBe([
            '2026-09-07 09:30',
            '2026-09-08 09:30',
            '2026-09-09 09:30',
            '2026-09-10 09:30',
        ]);
});

it('repeats on the chosen weekdays only', function () {
    expect(expand(['frequency' => 'weekly', 'byDay' => ['MO', 'WE']], '2026-09-07 08:00', '2026-09-18'))
        ->toBe([
            '2026-09-07 08:00',  // Monday
            '2026-09-09 08:00',  // Wednesday
            '2026-09-14 08:00',
            '2026-09-16 08:00',
        ]);
});

it('skips every other week when biweekly', function () {
    expect(expand(['frequency' => 'biweekly', 'byDay' => ['MO']], '2026-09-07 08:00', '2026-10-05'))
        ->toBe([
            '2026-09-07 08:00',
            '2026-09-21 08:00',
            '2026-10-05 08:00',
        ]);
});

it('repeats monthly on the day the series started', function () {
    expect(expand(['frequency' => 'monthly'], '2026-09-15 11:00', '2026-12-31'))
        ->toBe([
            '2026-09-15 11:00',
            '2026-10-15 11:00',
            '2026-11-15 11:00',
            '2026-12-15 11:00',
        ]);
});

it('has no occurrence in months too short for the chosen day', function () {
    // February 2027 has 28 days — a series on the 31st simply has no date there.
    expect(expand(['frequency' => 'monthly', 'byMonthDay' => 31], '2026-12-31 10:00', '2027-03-31'))
        ->toBe([
            '2026-12-31 10:00',
            '2027-01-31 10:00',
            '2027-03-31 10:00',
        ]);
});

it('falls back to the last day of the month when told to clamp', function () {
    expect(expand([
        'frequency' => 'monthly',
        'byMonthDay' => 31,
        'monthDayOverflow' => MonthDayOverflow::Clamp->value,
    ], '2026-12-31 10:00', '2027-03-31'))
        ->toBe([
            '2026-12-31 10:00',
            '2027-01-31 10:00',
            '2027-02-28 10:00',
            '2027-03-31 10:00',
        ]);
});

it('returns nothing when the series ends before it starts', function () {
    expect(expand(['frequency' => 'daily'], '2026-09-07 09:00', '2026-09-01'))->toBe([]);
});

it('includes the last day of the window', function () {
    expect(expand(['frequency' => 'daily'], '2026-09-07 23:30', '2026-09-08'))
        ->toBe(['2026-09-07 23:30', '2026-09-08 23:30']);
});

it('stops at the maximum number of occurrences', function () {
    $occurrences = expand(['frequency' => 'daily'], '2026-01-01 09:00', '2030-01-01');

    expect($occurrences)->toHaveCount(RecurrenceCalculator::MAX_OCCURRENCES);
});

it('rejects an unknown frequency and says what is expected', function () {
    expect(fn () => RecurrenceRule::fromArray(['frequency' => 'hourly']))
        ->toThrow(InvalidRecurrenceRule::class, 'Expected daily, weekly, biweekly or monthly');
});

it('rejects a weekly series without weekdays', function () {
    expect(fn () => RecurrenceRule::fromArray(['frequency' => 'weekly']))
        ->toThrow(InvalidRecurrenceRule::class);
});

it('rejects a day of month outside 1 to 31', function () {
    expect(fn () => RecurrenceRule::fromArray(['frequency' => 'monthly', 'byMonthDay' => 32]))
        ->toThrow(InvalidRecurrenceRule::class);
});

it('describes a rule in the active language', function () {
    $rule = RecurrenceRule::fromArray(['frequency' => 'weekly', 'byDay' => ['MO', 'WE']]);
    $calculator = app(RecurrenceCalculator::class);

    expect($calculator->describe($rule))->toBe('Weekly (Mon, Wed)');

    app()->setLocale('de');

    expect($calculator->describe($rule))->toBe('Wöchentlich (Mo, Mi)');
});

it('survives the round trip through an array', function () {
    $rules = ['frequency' => 'monthly', 'byMonthDay' => 15, 'monthDayOverflow' => 'clamp'];

    expect(RecurrenceRule::fromArray($rules)->toArray())->toBe($rules);
});
