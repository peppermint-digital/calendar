<?php

use Illuminate\Support\Facades\DB;
use Peppermint\Calendar\Exceptions\ForbiddenAttributeForKind;
use Peppermint\Calendar\Exceptions\UnknownEventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Models\CalendarEvent;

function makeEvent(array $attributes = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Jour Fixe',
        'starts_at' => '2026-09-08 09:00:00',
        'ends_at' => '2026-09-08 10:00:00',
    ], $attributes));
}

it('assigns a unique identity on creation', function () {
    expect(makeEvent()->uid)->not->toBeNull();
});

it('refuses an unregistered event kind instead of assuming one', function () {
    expect(fn () => makeEvent(['kind' => 'inventory']))
        ->toThrow(UnknownEventKind::class);
});

it('names the known kinds and where to register new ones', function () {
    try {
        makeEvent(['kind' => 'inventory']);
    } catch (UnknownEventKind $e) {
        expect($e->getMessage())
            ->toContain('business')
            ->toContain('private')
            ->toContain('config/calendar.php');
    }
});

it('rejects a field the event kind forbids', function () {
    expect(fn () => makeEvent(['kind' => 'private', 'meeting_url' => 'https://meet.example/x']))
        ->toThrow(ForbiddenAttributeForKind::class);
});

it('allows that same field for a kind that does not forbid it', function () {
    $event = makeEvent(['meeting_url' => 'https://meet.example/x']);

    expect($event->fresh()->meeting_url)->toBe('https://meet.example/x');
});

it('resolves the kind definition for an event', function () {
    expect(makeEvent(['kind' => 'private'])->kindDefinition()->label())->toBe('Private');
});

it('reports unknown kinds on direct lookup too', function () {
    expect(fn () => app(EventKindRegistry::class)->get('inventory'))
        ->toThrow(UnknownEventKind::class);
});

it('can be told not to load its own migrations', function () {
    // An application adopting an existing calendar_events table switches these
    // off; the package must then keep out of the migration path entirely.
    config()->set('calendar.run_migrations', false);

    $provider = new Peppermint\Calendar\CalendarServiceProvider(app());
    $provider->boot();

    expect(config('calendar.run_migrations'))->toBeFalse();
});

it('lets an existing row keep a forbidden value it already carried', function () {
    // Data older than the kind: the row was created before the application
    // adopted event kinds, and carries a field the kind now forbids. Editing
    // its title must not be blocked by that.
    $event = makeEvent(['meeting_url' => 'https://meet.example/legacy']);

    DB::table('calendar_events')->where('id', $event->id)->update(['kind' => 'private']);

    $legacy = CalendarEvent::find($event->id);
    $legacy->title = 'Renamed';

    expect(fn () => $legacy->save())->not->toThrow(ForbiddenAttributeForKind::class)
        ->and($legacy->fresh()->title)->toBe('Renamed');
});

it('still refuses to set a forbidden value on an existing row', function () {
    $event = makeEvent();
    DB::table('calendar_events')->where('id', $event->id)->update(['kind' => 'private']);

    $legacy = CalendarEvent::find($event->id);
    $legacy->meeting_url = 'https://meet.example/new';

    expect(fn () => $legacy->save())->toThrow(ForbiddenAttributeForKind::class);
});

it('treats a kind as creatable unless it says otherwise', function () {
    $offen = new class extends \Peppermint\Calendar\Kinds\EventKind
    {
        public function key(): string
        {
            return 'offen';
        }

        public function label(): string
        {
            return 'Offen';
        }
    };

    // A kind that only comes into being through another action — dragging a
    // task into the day, approving a leave request — says so.
    $abgeleitet = new class extends \Peppermint\Calendar\Kinds\EventKind
    {
        public function key(): string
        {
            return 'abgeleitet';
        }

        public function label(): string
        {
            return 'Abgeleitet';
        }

        public function isUserCreatable(): bool
        {
            return false;
        }
    };

    expect($offen->isUserCreatable())->toBeTrue()
        ->and($abgeleitet->isUserCreatable())->toBeFalse();
});
