<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Ics\IcsExporter;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Scheduling\ScheduleConflictFinder;
use Peppermint\Calendar\TimeBlocking\Board;
use Peppermint\Calendar\TimeBlocking\PlannableItem;

/**
 * An application that already had a calendar keeps its column names. Everything
 * here runs against a table that calls the owner `user_id` and the time range
 * `start_datetime` / `end_datetime` — the shape a long-lived application
 * actually has.
 */
beforeEach(function () {
    config()->set('calendar.columns', [
        'uid' => 'caldav_uid',
        'kind' => 'kind',
        'owner_id' => 'user_id',
        'subject_type' => 'subject_type',
        'subject_id' => 'subject_id',
        'starts_at' => 'start_datetime',
        'ends_at' => 'end_datetime',
        'all_day' => 'all_day',
        'visibility' => 'visibility',
    ]);

    config()->set('calendar.tables.events', 'legacy_events');

    Schema::create('legacy_events', function (Blueprint $table) {
        $table->id();
        $table->uuid('caldav_uid')->nullable();
        $table->string('kind', 64);
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('subject_type', 191)->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('location')->nullable();
        $table->string('meeting_url', 500)->nullable();
        $table->timestamp('start_datetime');
        $table->timestamp('end_datetime');
        $table->boolean('all_day')->default(false);
        $table->string('visibility', 16)->default('shared');
        $table->uuid('recurrence_group_id')->nullable();
        $table->boolean('is_recurrence_master')->default(false);
        $table->json('recurrence_rules')->nullable();
        $table->date('recurrence_until')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
});

afterEach(function () {
    // The adopted configuration must not outlive this file: the model resolves
    // its table and columns at call time, so a leftover setting would send the
    // next test's writes to a table with different column names.
    Schema::dropIfExists('legacy_events');
    config()->set('calendar.tables.events', 'calendar_events');
    config()->set('calendar.columns', []);
});

function adopted(array $attributes = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'user_id' => 1,
        'title' => 'Jour Fixe',
        'start_datetime' => '2026-09-08 09:00:00',
        'end_datetime' => '2026-09-08 10:00:00',
    ], $attributes));
}

it('creates events in a table with foreign column names', function () {
    $event = adopted();

    expect($event->fresh()->caldav_uid)->not->toBeNull()
        ->and($event->field('starts_at')->format('H:i'))->toBe('09:00');
});

it('casts the time range even though the columns are named differently', function () {
    expect(adopted()->fresh()->field('starts_at'))->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('lives in the table the application names', function () {
    adopted();

    expect(\Illuminate\Support\Facades\DB::table('legacy_events')->count())->toBe(1);
});

it('finds events in a window', function () {
    $inside = adopted();
    adopted(['start_datetime' => '2026-10-01 09:00:00', 'end_datetime' => '2026-10-01 10:00:00']);

    $found = CalendarEvent::inRange('2026-09-07', '2026-09-09')->pluck('id')->all();

    expect($found)->toBe([$inside->id]);
});

it('applies the visibility rule through the adopted owner column', function () {
    $mine = adopted();
    adopted(['user_id' => 2]);

    expect(CalendarEvent::visibleTo(1)->pluck('id')->all())->toBe([$mine->id])
        ->and(CalendarEvent::visibleTo(null)->count())->toBe(0);
});

it('still deletes according to the event kind', function () {
    $business = adopted();
    $private = adopted(['kind' => 'private']);

    $business->delete();
    $private->delete();

    expect(CalendarEvent::withTrashed()->find($business->id))->not->toBeNull()
        ->and(CalendarEvent::withTrashed()->find($private->id))->toBeNull();
});

it('detects conflicts on the adopted columns', function () {
    $existing = adopted();

    $conflicts = app(ScheduleConflictFinder::class)->conflicts(
        1,
        CarbonImmutable::parse('2026-09-08 09:30'),
        CarbonImmutable::parse('2026-09-08 10:30'),
    );

    expect($conflicts->pluck('id')->all())->toBe([$existing->id]);
});

it('builds a board on the adopted columns', function () {
    adopted(['subject_type' => 'task', 'subject_id' => 42]);

    $board = app(Board::class)->build(
        userId: 1,
        subjectType: 'task',
        items: [new PlannableItem(42, 'Write the offer'), new PlannableItem(43, 'Call back')],
        from: CarbonImmutable::parse('2026-09-07'),
        to: CarbonImmutable::parse('2026-09-13'),
    );

    expect(array_column($board['open'], 'id'))->toBe([43])
        ->and(array_column($board['scheduled'], 'id'))->toBe([42])
        ->and($board['events'])->toHaveCount(1);
});

it('exports iCalendar from the adopted columns', function () {
    $out = app(IcsExporter::class)->event(adopted());

    expect($out)
        ->toContain('DTSTART:20260908T090000Z')
        ->toContain('DTEND:20260908T100000Z')
        ->toContain('SUMMARY:Jour Fixe');
});

it('still writes correctly after being pointed back at the default table', function () {
    // Regression guard. A non-empty $guarded makes Eloquent cache the table's
    // column list statically per model class. With a model that can change its
    // table at runtime, that cache outlives the switch — and fill() then drops
    // every attribute the previous table did not have, without an error.
    adopted();

    config()->set('calendar.tables.events', 'calendar_events');
    config()->set('calendar.columns', []);

    $event = CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Back on the default table',
        'starts_at' => '2026-09-08 09:00:00',
        'ends_at' => '2026-09-08 10:00:00',
    ]);

    expect($event->fresh()->getAttributes())
        ->toHaveKeys(['owner_id', 'starts_at', 'ends_at']);
});
