<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Recurrence\SeriesEditor;

function serie(array $attributes = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Jour Fixe',
        'starts_at' => '2026-09-01 09:00:00',
        'ends_at' => '2026-09-01 10:00:00',
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['TU']],
    ], $attributes));
}

it('beendet die alte Reihe am Tag vor dem Stichtag', function () {
    // Zwei Reihen, nicht eine mit Sonderfällen: Eine Serie, die ihre Regel in
    // der Mitte wechselt, liesse sich als iCalendar nicht ausdruecken — und
    // wuerde rueckwirkend die Vergangenheit umschreiben.
    $alt = serie();

    $neu = app(SeriesEditor::class)->splitSeries($alt, CarbonImmutable::parse('2026-10-06 09:00'));

    expect($alt->fresh()->recurrence_until->toDateString())->toBe('2026-10-05')
        ->and($neu->id)->not->toBe($alt->id)
        ->and($neu->starts_at->toDateString())->toBe('2026-10-06');
});

it('uebernimmt die Aenderungen in die neue Reihe, nicht in die alte', function () {
    $alt = serie();

    $neu = app(SeriesEditor::class)->splitSeries(
        $alt,
        CarbonImmutable::parse('2026-10-06 09:00'),
        ['recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['TH']], 'title' => 'Jour Fixe (neu)'],
    );

    expect($neu->recurrence_rules['byDay'])->toBe(['TH'])
        ->and($neu->title)->toBe('Jour Fixe (neu)')
        ->and($alt->fresh()->recurrence_rules['byDay'])->toBe(['TU'])
        ->and($alt->fresh()->title)->toBe('Jour Fixe');
});

it('sortiert die Ausnahmen auf beide Reihen', function () {
    // Ohne das kaeme ein laengst abgesagter Termin wieder zum Vorschein.
    $alt = serie(['recurrence_exceptions' => ['2026-09-15', '2026-10-20']]);

    $neu = app(SeriesEditor::class)->splitSeries($alt, CarbonImmutable::parse('2026-10-06 09:00'));

    expect($alt->fresh()->recurrence_exceptions)->toBe(['2026-09-15'])
        ->and($neu->recurrence_exceptions)->toBe(['2026-10-20']);
});

it('teilt nicht, wenn der Stichtag das erste Vorkommen ist', function () {
    // Dann waere die alte Reihe leer — und „dieser und alle kuenftigen" ist
    // dasselbe wie „die ganze Serie".
    $alt = serie();

    $ergebnis = app(SeriesEditor::class)->splitSeries(
        $alt,
        CarbonImmutable::parse('2026-09-01 09:00'),
        ['title' => 'Umbenannt'],
    );

    expect($ergebnis->id)->toBe($alt->id)
        ->and($ergebnis->title)->toBe('Umbenannt')
        ->and(CalendarEvent::count())->toBe(1);
});
