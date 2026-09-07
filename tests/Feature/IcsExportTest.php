<?php

use Peppermint\Calendar\Ics\IcsExporter;
use Peppermint\Calendar\Ics\IcsWriter;
use Peppermint\Calendar\Models\CalendarEvent;

function icsEvent(array $attributes = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Jour Fixe',
        'starts_at' => '2026-09-08 09:00:00',
        'ends_at' => '2026-09-08 10:00:00',
    ], $attributes));
}

function ics(CalendarEvent $event): string
{
    return app(IcsExporter::class)->event($event);
}

it('writes a complete VEVENT', function () {
    $out = ics(icsEvent(['location' => 'Room 2']));

    expect($out)
        ->toContain('BEGIN:VEVENT')
        ->toContain('SUMMARY:Jour Fixe')
        ->toContain('LOCATION:Room 2')
        ->toContain('DTSTART:20260908T090000Z')
        ->toContain('DTEND:20260908T100000Z')
        ->toContain('END:VEVENT');
});

it('ends every line with CRLF as the format requires', function () {
    expect(ics(icsEvent()))->toContain("BEGIN:VEVENT\r\n");
});

it('carries the stable event identity into the UID', function () {
    $event = icsEvent();

    expect(ics($event))->toContain('UID:'.$event->uid.'@calendar.local');
});

it('marks all-day events as dates and ends them on the following day', function () {
    // DTEND is exclusive: a single all-day event on the 8th ends on the 9th.
    $out = ics(icsEvent(['all_day' => true, 'ends_at' => '2026-09-08 23:59:00']));

    expect($out)
        ->toContain('DTSTART;VALUE=DATE:20260908')
        ->toContain('DTEND;VALUE=DATE:20260909');
});

it('escapes characters that would otherwise break the structure', function () {
    $out = ics(icsEvent(['title' => 'Planning; costs, notes\\draft']));

    expect($out)->toContain('SUMMARY:Planning\; costs\, notes\\\\draft');
});

it('turns line breaks in the description into the escaped form', function () {
    $out = ics(icsEvent(['description' => "First line\nSecond line"]));

    expect($out)->toContain('DESCRIPTION:First line\nSecond line')
        ->and($out)->not->toContain("DESCRIPTION:First line\n");
});

it('strips markup from the description', function () {
    expect(ics(icsEvent(['description' => '<p>Agenda <strong>final</strong></p>'])))
        ->toContain('DESCRIPTION:Agenda final');
});

it('folds long lines and marks the continuation with a space', function () {
    $out = ics(icsEvent(['title' => str_repeat('Quarterly planning ', 12)]));

    foreach (explode("\r\n", $out) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    expect($out)->toContain("\r\n ");
});

it('never folds in the middle of a multi-byte character', function () {
    $out = ics(icsEvent(['title' => str_repeat('Geschäftsführung Jubiläum ', 6)]));

    foreach (explode("\r\n", $out) as $line) {
        expect(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
});

it('exports a weekly series as a recurrence rule', function () {
    $out = ics(icsEvent([
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO', 'WE']],
        'recurrence_until' => '2026-12-31',
    ]));

    expect($out)->toContain('RRULE:FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261231T235959Z');
});

it('expresses every two weeks as an interval', function () {
    expect(ics(icsEvent(['recurrence_rules' => ['frequency' => 'biweekly', 'byDay' => ['MO']]])))
        ->toContain('RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO');
});

it('exports a monthly series with its day of month', function () {
    expect(ics(icsEvent(['recurrence_rules' => ['frequency' => 'monthly', 'byMonthDay' => 15]])))
        ->toContain('RRULE:FREQ=MONTHLY;BYMONTHDAY=15');
});

it('writes no recurrence rule for a single event', function () {
    expect(ics(icsEvent()))->not->toContain('RRULE');
});

it('lists attendees with their response state', function () {
    $event = icsEvent();
    $event->attendees()->create(['email' => 'a@example.test', 'name' => 'Alex Doe', 'status' => 'accepted']);
    $event->attendees()->create(['email' => 'b@example.test', 'status' => 'pending']);

    $out = ics($event->fresh('attendees'));

    expect($out)
        ->toContain('CN="Alex Doe"')
        ->toContain('PARTSTAT=ACCEPTED:mailto:a@example.test')
        ->toContain('PARTSTAT=NEEDS-ACTION:mailto:b@example.test');
});

it('marks a trashed event as cancelled so subscribers remove it', function () {
    $event = icsEvent();
    $event->delete();

    expect(ics(CalendarEvent::withTrashed()->find($event->id)))->toContain('STATUS:CANCELLED');
});

it('hides details of confidential events from clients that honour it', function () {
    expect(ics(icsEvent(['visibility' => 'confidential'])))->toContain('CLASS:PRIVATE');
});

it('wraps events in a calendar with the configured identity', function () {
    $out = app(IcsExporter::class)->calendar([icsEvent()], 'Team');

    expect($out)
        ->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toContain('PRODID:-//Peppermint//Calendar//EN')
        ->toContain('X-WR-CALNAME:Team')
        ->toEndWith("END:VCALENDAR\r\n");
});

it('folds exactly at the octet boundary', function () {
    $writer = new IcsWriter;
    $writer->property('SUMMARY', str_repeat('a', 100));

    $lines = explode("\r\n", trim($writer->toString()));

    expect(strlen($lines[0]))->toBe(75)
        ->and($lines[1])->toStartWith(' ');
});
