<?php

use Peppermint\Calendar\Models\CalendarCategory;
use Peppermint\Calendar\Models\CalendarEvent;

it('nimmt die gemeinsame Abbildung, wenn es keine tabellenbezogene gibt', function () {
    // Bestandskonfigurationen kennen nur diese Form und muessen unveraendert
    // weiterlaufen.
    config()->set('calendar.columns', ['owner_id' => 'user_id']);

    expect(CalendarEvent::column('owner_id'))->toBe('user_id')
        ->and(CalendarCategory::column('owner_id'))->toBe('user_id');
});

it('laesst die tabellenbezogene Abbildung gewinnen', function () {
    // Der Fall, um den es geht: `owner_id` gibt es an BEIDEN Tabellen. Wer
    // seine Termine auf user_id abbildet, hatte damit auch die Kategorien
    // darauf abgebildet — was zufaellig passen kann und ebenso zufaellig nicht.
    config()->set('calendar.columns', [
        'owner_id' => 'user_id',
        'categories' => ['owner_id' => 'created_by'],
    ]);

    expect(CalendarEvent::column('owner_id'))->toBe('user_id')
        ->and(CalendarCategory::column('owner_id'))->toBe('created_by');
});

it('faellt auf den eigenen Namen zurueck, wenn nichts abgebildet ist', function () {
    config()->set('calendar.columns', []);

    expect(CalendarEvent::column('starts_at'))->toBe('starts_at')
        ->and(CalendarCategory::column('label'))->toBe('label');
});

it('trennt auch die Termin-Seite, wenn jemand sie ausdruecklich nennt', function () {
    config()->set('calendar.columns', [
        'owner_id' => 'user_id',
        'events' => ['owner_id' => 'organizer_id'],
    ]);

    expect(CalendarEvent::column('owner_id'))->toBe('organizer_id')
        ->and(CalendarCategory::column('owner_id'))->toBe('user_id');
});
