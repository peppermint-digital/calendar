<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Models\CalendarEventAttendee;
use Peppermint\Calendar\Tests\Fixtures\BusinessProfile;
use Peppermint\Calendar\Tests\Fixtures\PrivateProfile;

function eventWithProfile(string $kind): CalendarEvent
{
    $event = CalendarEvent::create([
        'kind' => $kind,
        'owner_id' => 1,
        'title' => 'Termin',
        'starts_at' => '2026-09-08 09:00:00',
        'ends_at' => '2026-09-08 10:00:00',
    ]);

    $event->attendees()->create(['user_id' => 2, 'email' => 'kollege@example.test']);

    $kind === 'business'
        ? BusinessProfile::create(['event_id' => $event->id, 'project_id' => 7])
        : PrivateProfile::create(['event_id' => $event->id, 'habit_id' => 3]);

    return $event;
}

it('legt einen geschaeftlichen Termin in den Papierkorb statt ihn zu entfernen', function () {
    $event = eventWithProfile('business');

    $event->delete();

    expect(CalendarEvent::withTrashed()->find($event->id))->not->toBeNull()
        ->and(CalendarEvent::find($event->id))->toBeNull()
        ->and($event->fresh()->deleted_at)->not->toBeNull();
});

it('behaelt Teilnehmer und Profil, solange der Termin im Papierkorb liegt', function () {
    $event = eventWithProfile('business');

    $event->delete();

    expect(CalendarEventAttendee::where('event_id', $event->id)->count())->toBe(1)
        ->and(BusinessProfile::where('event_id', $event->id)->count())->toBe(1);
});

it('holt einen Termin aus dem Papierkorb zurueck', function () {
    $event = eventWithProfile('business');
    $event->delete();

    CalendarEvent::withTrashed()->find($event->id)->restore();

    expect(CalendarEvent::find($event->id))->not->toBeNull();
});

it('entfernt einen privaten Termin sofort — samt Teilnehmern und Profil', function () {
    $event = eventWithProfile('private');

    $event->delete();

    expect(CalendarEvent::withTrashed()->find($event->id))->toBeNull()
        ->and(DB::table('calendar_event_attendees')->where('event_id', $event->id)->count())->toBe(0)
        ->and(PrivateProfile::where('event_id', $event->id)->count())->toBe(0);
});

it('raeumt den Papierkorb erst nach der Frist der jeweiligen Art', function () {
    $frisch = eventWithProfile('business');
    $alt = eventWithProfile('business');

    $frisch->delete();
    $alt->delete();
    CalendarEvent::withTrashed()->whereKey($alt->id)->update(['deleted_at' => Carbon::now()->subDays(31)]);

    $this->artisan('calendar:purge-trash')->assertSuccessful();

    expect(CalendarEvent::withTrashed()->find($alt->id))->toBeNull()
        ->and(CalendarEvent::withTrashed()->find($frisch->id))->not->toBeNull();
});

it('loescht im Probelauf nichts', function () {
    $alt = eventWithProfile('business');
    $alt->delete();
    CalendarEvent::withTrashed()->whereKey($alt->id)->update(['deleted_at' => Carbon::now()->subDays(31)]);

    $this->artisan('calendar:purge-trash', ['--dry-run' => true])->assertSuccessful();

    expect(CalendarEvent::withTrashed()->find($alt->id))->not->toBeNull();
});
