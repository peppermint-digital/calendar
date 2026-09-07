<?php

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
