<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Scheduling\PlannedSubjects;
use Peppermint\Calendar\Scheduling\ScheduleConflictFinder;

function block(string $start, string $end, array $attributes = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Focus',
        'starts_at' => $start,
        'ends_at' => $end,
    ], $attributes));
}

function conflicts(string $start, string $end, ?int $ignore = null, int $owner = 1): array
{
    return app(ScheduleConflictFinder::class)
        ->conflicts($owner, CarbonImmutable::parse($start), CarbonImmutable::parse($end), ignoreEventId: $ignore)
        ->pluck('id')
        ->all();
}

it('reports an overlapping slot as a conflict', function () {
    $existing = block('2026-09-08 10:00:00', '2026-09-08 11:00:00');

    expect(conflicts('2026-09-08 10:30:00', '2026-09-08 11:30:00'))->toBe([$existing->id]);
});

it('treats back-to-back slots as free', function () {
    block('2026-09-08 10:00:00', '2026-09-08 11:00:00');

    expect(conflicts('2026-09-08 11:00:00', '2026-09-08 12:00:00'))->toBe([])
        ->and(conflicts('2026-09-08 09:00:00', '2026-09-08 10:00:00'))->toBe([]);
});

it('reports a slot fully inside another as a conflict', function () {
    $existing = block('2026-09-08 09:00:00', '2026-09-08 17:00:00');

    expect(conflicts('2026-09-08 12:00:00', '2026-09-08 13:00:00'))->toBe([$existing->id]);
});

it('does not let an event collide with itself while being moved', function () {
    $existing = block('2026-09-08 10:00:00', '2026-09-08 11:00:00');

    expect(conflicts('2026-09-08 10:15:00', '2026-09-08 11:15:00', ignore: $existing->id))->toBe([]);
});

it('ignores what other people have planned', function () {
    block('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['owner_id' => 2]);

    expect(conflicts('2026-09-08 10:00:00', '2026-09-08 11:00:00'))->toBe([]);
});

it('names every colliding event, not just the first', function () {
    $morning = block('2026-09-08 09:00:00', '2026-09-08 10:30:00');
    $later = block('2026-09-08 10:15:00', '2026-09-08 11:00:00');

    expect(conflicts('2026-09-08 09:30:00', '2026-09-08 10:45:00'))->toBe([$morning->id, $later->id]);
});

it('knows which tasks a person has already planned', function () {
    block('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);

    $planned = app(PlannedSubjects::class);

    expect($planned->ever(1, 'task'))->toBe([42])
        ->and($planned->ever(2, 'task'))->toBe([]);
});

it('reports a recurring task as planned only inside the visible window', function () {
    block('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);

    $planned = app(PlannedSubjects::class);

    $thisWeek = $planned->within(1, 'task', CarbonImmutable::parse('2026-09-07'), CarbonImmutable::parse('2026-09-14'));
    $nextWeek = $planned->within(1, 'task', CarbonImmutable::parse('2026-09-14'), CarbonImmutable::parse('2026-09-21'));

    expect($thisWeek)->toBe([42])
        ->and($nextWeek)->toBe([]);
});

it('does not hide a task because someone else planned it', function () {
    block('2026-09-08 10:00:00', '2026-09-08 11:00:00', [
        'owner_id' => 2, 'subject_type' => 'task', 'subject_id' => 42,
    ]);

    expect(app(PlannedSubjects::class)->ever(1, 'task'))->toBe([]);
});

it('counts a task once even when it has several blocks', function () {
    block('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);
    block('2026-09-09 10:00:00', '2026-09-09 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);

    expect(app(PlannedSubjects::class)->ever(1, 'task'))->toBe([42]);
});

it('keeps subjects of different types apart', function () {
    block('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);
    block('2026-09-08 12:00:00', '2026-09-08 13:00:00', ['subject_type' => 'habit', 'subject_id' => 42]);

    $planned = app(PlannedSubjects::class);

    expect($planned->ever(1, 'task'))->toBe([42])
        ->and($planned->ever(1, 'habit'))->toBe([42])
        ->and(CalendarEvent::about('habit', 42)->count())->toBe(1);
});

it('finds the blocks belonging to one task', function () {
    $planned = block('2026-09-08 10:00:00', '2026-09-08 11:00:00', ['subject_type' => 'task', 'subject_id' => 42]);
    block('2026-09-08 12:00:00', '2026-09-08 13:00:00', ['subject_type' => 'task', 'subject_id' => 43]);

    expect(CalendarEvent::about('task', 42)->pluck('id')->all())->toBe([$planned->id]);
});
