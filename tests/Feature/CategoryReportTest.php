<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Services\CategoryReportAssembler;
use Peppermint\Calendar\Tests\Fixtures\CategorisedKind;

/**
 * Wie viel Zeit ging in welche Kategorie? (#7846)
 *
 * Gab es bis zum 18.10.2026 nur im Manager. Geprüft wird vor allem die eine
 * Regel, die man leicht vergisst: Gezählt wird der Termin, den es tatsächlich
 * gab, nicht die Serienregel.
 */
beforeEach(function () {
    CategorisedKind::$nachTitel = [];
});

/**
 * Legt einen Termin an und sagt, welche Kategorien er trägt.
 *
 * Die Kategorien gehen über den TITEL an die Terminart — `kindDefinition()
 * ->categories()` ist der Weg, den auch die Agenda, die Einzelansicht und der
 * ICS-Export benutzen.
 */
function berichtTermin(array $werte = [], array $kategorien = []): CalendarEvent
{
    $titel = $werte['title'] ?? 'Termin '.count(CategorisedKind::$nachTitel);
    CategorisedKind::$nachTitel[$titel] = $kategorien;

    return CalendarEvent::create(array_merge([
        'kind' => 'categorised',
        'title' => $titel,
        'owner_id' => 1,
        'starts_at' => '2026-09-08 09:00',
        'ends_at' => '2026-09-08 10:00',
    ], $werte));
}

function bericht(string $von = '2026-09-07', string $bis = '2026-09-30'): array
{
    return app(CategoryReportAssembler::class)->forUser(
        1,
        CarbonImmutable::parse($von)->startOfDay(),
        CarbonImmutable::parse($bis)->endOfDay(),
    );
}

it('summiert Dauer und Anzahl je Kategorie', function () {
    berichtTermin([], ['Kundentermin']);
    berichtTermin(['starts_at' => '2026-09-09 09:00', 'ends_at' => '2026-09-09 11:00'], ['Kundentermin']);

    $zeile = collect(bericht()['categories'])->firstWhere('label', 'Kundentermin');

    expect($zeile['count'])->toBe(2)
        ->and($zeile['minutes'])->toBe(180)
        ->and($zeile['hours'])->toBe(3.0);
});

/**
 * Die Regel, um die es geht: Eine wöchentliche Besprechung steht sonst mit
 * einer Stunde im Bericht statt mit vieren.
 */
it('zaehlt jedes Vorkommen einer Serie, nicht die Regel', function () {
    berichtTermin([
        // Die Form des Pakets, nicht die RFC-Zeichenkette: `recurrence_rules`
        // plus `recurrence_until`. Ein `rrule` gibt es als Spalte nicht — die
        // Art weist es mit `AttributeWithoutHome` ab, und das ist richtig so.
        'starts_at' => '2026-09-07 09:00',
        'ends_at' => '2026-09-07 10:00',
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
        'recurrence_until' => '2026-09-28',
    ], ['Jour fixe']);

    $zeile = collect(bericht()['categories'])->firstWhere('label', 'Jour fixe');

    expect($zeile['count'])->toBe(4)->and($zeile['minutes'])->toBe(240);
});

/**
 * Eine Serie, die VOR dem Fenster beginnt, hat ihre Vorkommen mitten darin.
 * Wer nur `inRange` fragt, verliert genau die Serien, um die es geht.
 */
it('findet eine Serie, die vor dem Fenster beginnt', function () {
    berichtTermin([
        'starts_at' => '2026-08-03 09:00',
        'ends_at' => '2026-08-03 10:00',
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['MO']],
        'recurrence_until' => '2026-10-31',
    ], ['Jour fixe']);

    $zeile = collect(bericht('2026-09-07', '2026-09-21')['categories'])->firstWhere('label', 'Jour fixe');

    expect($zeile)->not->toBeNull()->and($zeile['count'])->toBeGreaterThan(0);
});

/**
 * Ein Termin mit zwei Kategorien zählt bei BEIDEN voll — „Kundentermin" und
 * „abrechenbar" sind keine zwei halben Stunden, sondern eine Stunde, die
 * beides ist. Die Gesamtzeit zählt ihn trotzdem nur einmal.
 */
it('zaehlt einen Termin bei jeder seiner Kategorien voll, die Summe aber einmal', function () {
    berichtTermin([], ['Kundentermin', 'Abrechenbar']);

    $b = bericht();

    expect(collect($b['categories'])->firstWhere('label', 'Kundentermin')['minutes'])->toBe(60)
        ->and(collect($b['categories'])->firstWhere('label', 'Abrechenbar')['minutes'])->toBe(60)
        ->and($b['total']['minutes'])->toBe(60);
});

it('fuehrt Termine ohne Kategorie getrennt', function () {
    berichtTermin();
    berichtTermin(['starts_at' => '2026-09-09 09:00', 'ends_at' => '2026-09-09 10:00'], ['Kundentermin']);

    $b = bericht();

    expect($b['uncategorized']['count'])->toBe(1)
        ->and($b['uncategorized']['minutes'])->toBe(60)
        ->and($b['total']['count'])->toBe(2);
});

/** Nach Dauer sortiert — die Frage ist „wofür ging die Zeit drauf". */
it('stellt die laengste Kategorie nach vorn', function () {
    berichtTermin([], ['Kurz']);
    berichtTermin(['starts_at' => '2026-09-09 09:00', 'ends_at' => '2026-09-09 14:00'], ['Lang']);

    expect(collect(bericht()['categories'])->pluck('label')->first())->toBe('Lang');
});

it('zeigt den Termin eines anderen nicht', function () {
    berichtTermin(['owner_id' => 2], ['Fremd']);

    expect(bericht()['categories'])->toBe([]);
});

/** Minuten UND Stunden: die eine zum Rechnen, die andere zum Lesen. */
it('liefert beide Masse', function () {
    berichtTermin(['ends_at' => '2026-09-08 10:30'], ['X']);

    $zeile = collect(bericht()['categories'])->firstWhere('label', 'X');

    expect($zeile['minutes'])->toBe(90)->and($zeile['hours'])->toBe(1.5);
});
