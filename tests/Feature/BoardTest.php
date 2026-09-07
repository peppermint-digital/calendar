<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\TimeBlocking\Board;
use Peppermint\Calendar\TimeBlocking\BoardPreferences;
use Peppermint\Calendar\TimeBlocking\PlannableItem;

function boardBlock(string $start, string $end, array $attributes = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Focus',
        'starts_at' => $start,
        'ends_at' => $end,
    ], $attributes));
}

function board(array $items, array $preferences = [], string $from = '2026-09-07', string $to = '2026-09-13'): array
{
    return app(Board::class)->build(
        userId: 1,
        subjectType: 'task',
        items: array_map(fn (array $i) => PlannableItem::fromArray($i), $items),
        from: CarbonImmutable::parse($from),
        to: CarbonImmutable::parse($to),
        preferences: BoardPreferences::fromArray($preferences),
    );
}

it('keeps an unplanned task in the open list', function () {
    $result = board([['id' => 42, 'title' => 'Write the offer']]);

    expect(array_column($result['open'], 'id'))->toBe([42])
        ->and($result['scheduled'])->toBe([]);
});

it('moves a one-off task out of the list once it is planned at all', function () {
    boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);

    $result = board([['id' => 42, 'title' => 'Write the offer']]);

    expect($result['open'])->toBe([])
        ->and(array_column($result['scheduled'], 'id'))->toBe([42]);
});

it('keeps a one-off task out of the list in later windows too', function () {
    boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);

    $result = board([['id' => 42, 'title' => 'Write the offer']], from: '2026-09-14', to: '2026-09-20');

    expect($result['open'])->toBe([]);
});

it('brings a recurring task back in a window it is not planned in', function () {
    boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 7]);

    $thisWeek = board([['id' => 7, 'title' => 'Weekly review', 'recurring' => true]]);
    $nextWeek = board([['id' => 7, 'title' => 'Weekly review', 'recurring' => true]], from: '2026-09-14', to: '2026-09-20');

    expect($thisWeek['open'])->toBe([])
        ->and(array_column($nextWeek['open'], 'id'))->toBe([7]);
});

it('does not remove an item because someone else planned it', function () {
    boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00', [
        'owner_id' => 2, 'subject_type' => 'task', 'subject_id' => 42,
    ]);

    expect(array_column(board([['id' => 42, 'title' => 'Write the offer']])['open'], 'id'))->toBe([42]);
});

it('hands the application data through untouched', function () {
    $result = board([['id' => 42, 'title' => 'Write the offer', 'meta' => ['project' => 'Acme', 'effort' => 2.5]]]);

    expect($result['open'][0]['meta'])->toBe(['project' => 'Acme', 'effort' => 2.5]);
});

it('shows only the events of the window', function () {
    $inside = boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00');
    boardBlock('2026-09-20 10:00:00', '2026-09-20 11:00:00');

    expect(array_column(board([])['events'], 'id'))->toBe([$inside->id]);
});

it('hides weekends unless asked for them', function () {
    boardBlock('2026-09-12 10:00:00', '2026-09-12 11:00:00');       // Saturday
    $weekday = boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00');

    expect(array_column(board([])['events'], 'id'))->toBe([$weekday->id])
        ->and(count(board([], ['showWeekends' => true])['events']))->toBe(2);
});

it('leaves out other peoples blocks by default', function () {
    boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['owner_id' => 2]);
    $mine = boardBlock('2026-09-08 12:00:00', '2026-09-08 13:00:00');

    expect(array_column(board([])['events'], 'id'))->toBe([$mine->id]);
});

it('reports a day that collides with itself', function () {
    $first = boardBlock('2026-09-08 10:00:00', '2026-09-08 11:30:00');
    $second = boardBlock('2026-09-08 11:00:00', '2026-09-08 12:00:00');

    expect(board([])['collisions'])->toBe([[$first->id, $second->id]]);
});

it('does not report back-to-back blocks as a collision', function () {
    boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00');
    boardBlock('2026-09-08 11:00:00', '2026-09-08 12:00:00');

    expect(board([])['collisions'])->toBe([]);
});

it('opens with the defaults a planning view needs', function () {
    $preferences = board([])['preferences'];

    expect($preferences)->toBe([
        'onlyOwnBlocks' => true,
        'onlyOwnItems' => true,
        'showWeekends' => false,
        'showAllDayEvents' => false,
        'kinds' => [],
    ]);
});

it('restricts the board to the chosen event kinds', function () {
    $business = boardBlock('2026-09-08 10:00:00', '2026-09-08 11:00:00');
    boardBlock('2026-09-08 12:00:00', '2026-09-08 13:00:00', ['kind' => 'private']);

    expect(array_column(board([], ['kinds' => ['business']])['events'], 'id'))->toBe([$business->id]);
});

it('survives preferences round-tripping through storage', function () {
    $stored = ['onlyOwnBlocks' => false, 'showWeekends' => true, 'kinds' => ['private']];

    expect(BoardPreferences::fromArray($stored)->toArray())->toBe([
        'onlyOwnBlocks' => false,
        'onlyOwnItems' => true,
        'showWeekends' => true,
        'showAllDayEvents' => false,
        'kinds' => ['private'],
    ]);
});
