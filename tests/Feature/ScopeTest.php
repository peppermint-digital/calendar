<?php

use Peppermint\Calendar\Models\CalendarEvent;

function eventBetween(string $von, string $bis, int $owner = 1): CalendarEvent
{
    return CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => $owner,
        'title' => 'Termin',
        'starts_at' => $von,
        'ends_at' => $bis,
    ]);
}

it('findet Termine, die in den Zeitraum hineinragen — nicht nur die, die darin beginnen', function () {
    $ueberNacht = eventBetween('2026-09-07 23:00:00', '2026-09-08 01:00:00');
    eventBetween('2026-09-09 09:00:00', '2026-09-09 10:00:00');

    $treffer = CalendarEvent::inRange('2026-09-08 00:00:00', '2026-09-09 00:00:00')->get();

    expect($treffer->pluck('id')->all())->toBe([$ueberNacht->id]);
});

it('gibt ohne erkennbaren Nutzer nichts frei', function () {
    eventBetween('2026-09-08 09:00:00', '2026-09-08 10:00:00');

    expect(CalendarEvent::visibleTo(null)->count())->toBe(0);
});

it('zeigt eigene Termine und solche, an denen man teilnimmt', function () {
    $eigener = eventBetween('2026-09-08 09:00:00', '2026-09-08 10:00:00', owner: 1);
    $fremder = eventBetween('2026-09-08 11:00:00', '2026-09-08 12:00:00', owner: 2);
    $eingeladen = eventBetween('2026-09-08 13:00:00', '2026-09-08 14:00:00', owner: 2);
    $eingeladen->attendees()->create(['user_id' => 1, 'email' => 'ich@example.test']);

    $sichtbar = CalendarEvent::visibleTo(1)->pluck('id')->all();

    expect($sichtbar)->toContain($eigener->id)
        ->toContain($eingeladen->id)
        ->not->toContain($fremder->id);
});

it('filtert nach Terminart', function () {
    eventBetween('2026-09-08 09:00:00', '2026-09-08 10:00:00');
    $privat = CalendarEvent::create([
        'kind' => 'private',
        'owner_id' => 1,
        'title' => 'Zahnarzt',
        'starts_at' => '2026-09-08 15:00:00',
        'ends_at' => '2026-09-08 16:00:00',
    ]);

    expect(CalendarEvent::ofKind('private')->pluck('id')->all())->toBe([$privat->id]);
});
