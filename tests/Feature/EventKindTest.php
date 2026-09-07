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

it('vergibt beim Anlegen eine eindeutige Kennung', function () {
    expect(makeEvent()->uid)->not->toBeNull();
});

it('lehnt eine nicht registrierte Terminart ab, statt eine anzunehmen', function () {
    expect(fn () => makeEvent(['kind' => 'inventory']))
        ->toThrow(UnknownEventKind::class);
});

it('nennt in der Fehlermeldung die bekannten Arten und wo man sie einträgt', function () {
    try {
        makeEvent(['kind' => 'inventory']);
    } catch (UnknownEventKind $e) {
        expect($e->getMessage())
            ->toContain('business')
            ->toContain('private')
            ->toContain('config/calendar.php');
    }
});

it('verweigert ein Feld, das die Terminart ausschliesst', function () {
    expect(fn () => makeEvent(['kind' => 'private', 'meeting_url' => 'https://meet.example/x']))
        ->toThrow(ForbiddenAttributeForKind::class);
});

it('laesst dasselbe Feld bei einer Art zu, die es nicht ausschliesst', function () {
    $event = makeEvent(['meeting_url' => 'https://meet.example/x']);

    expect($event->fresh()->meeting_url)->toBe('https://meet.example/x');
});

it('kennt zu jedem Termin seine Art-Definition', function () {
    expect(makeEvent(['kind' => 'private'])->kindDefinition()->label())->toBe('Privat');
});

it('meldet unbekannte Arten auch beim direkten Nachschlagen', function () {
    expect(fn () => app(EventKindRegistry::class)->get('inventory'))
        ->toThrow(UnknownEventKind::class);
});
