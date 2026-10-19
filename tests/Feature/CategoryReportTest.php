<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Services\CategoryReportAssembler;
use Peppermint\Calendar\Tests\Fixtures\CategorisedKind;
use Peppermint\Calendar\Tests\Fixtures\GroupedBlockKind;

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

/** Ein Zeitblock: keine Kategorie, aber eine Gruppe (siehe `GroupedBlockKind`). */
function blockTermin(string $titel, int $minuten): CalendarEvent
{
    return CalendarEvent::create([
        'kind' => 'grouped_block',
        'title' => $titel,
        'owner_id' => 1,
        'starts_at' => '2026-09-08 09:00',
        'ends_at' => CarbonImmutable::parse('2026-09-08 09:00')->addMinutes($minuten)->toDateTimeString(),
    ]);
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

/*
|--------------------------------------------------------------------------
| Was ohne Kategorie bleibt, aufschlüsseln (#7846)
|--------------------------------------------------------------------------
|
| Ein Zeitblock trägt bewusst keine Kategorie: Er zeigt auf eine Aufgabe, und
| die trägt den Zweck präziser, als ein Etikett es könnte. Seine Zeit ist
| trotzdem nicht nichts — im Manager sind 483 von 1211 Terminen solche Blöcke,
| 40 Prozent des Kalenders, und die standen in einer einzigen Sammelzeile.
|
| Abgeleitet, nicht gewählt: Das Etikett kommt aus dem Subjekt. Deshalb eine
| eigene Liste — gewählte und abgeleitete Zahlen, die in einer Tabelle gleich
| aussehen, sind der Anfang eines lügenden Berichts.
*/

beforeEach(function () {
    GroupedBlockKind::$nachTitel = [];
});

it('schluesselt auf, was ohne Kategorie bleibt', function () {
    GroupedBlockKind::$nachTitel = ['Block A' => 'Projekt Nord', 'Block B' => 'Projekt Nord', 'Block C' => 'Projekt Süd'];

    // Unterschiedliche Summen, damit der Test die SORTIERUNG prüft und nicht
    // nur die Zahlen: Bei Gleichstand ist die Reihenfolge offen, und ein Test
    // darüber wäre je nach Laune grün.
    blockTermin('Block A', 60);
    blockTermin('Block B', 30);
    blockTermin('Block C', 120);

    $bericht = bericht();

    expect(collect($bericht['groups'])->pluck('minutes', 'label')->all())
        ->toBe(['Projekt Süd' => 120, 'Projekt Nord' => 90]);
});

/**
 * Die Invariante, die den Bericht ehrlich hält: Die Gruppen schlüsseln NUR
 * auf, was ohne Kategorie bleibt. Ihre Summe darf also nie größer sein als
 * „ohne Kategorie" — sonst zählte ein Termin doppelt.
 */
it('zaehlt in den Gruppen nie mehr als „ohne Kategorie"', function () {
    GroupedBlockKind::$nachTitel = ['Block' => 'Projekt Nord'];

    blockTermin('Block', 60);
    berichtTermin(['title' => 'Mit Kategorie'], ['Kundentermin']);

    $bericht = bericht();

    $summe = collect($bericht['groups'])->sum('minutes');

    expect($summe)->toBeLessThanOrEqual($bericht['uncategorized']['minutes']);
});

it('laesst eine Art ohne Gruppe in der Sammelzeile', function () {
    GroupedBlockKind::$nachTitel = [];

    blockTermin('Ohne alles', 45);

    $bericht = bericht();

    expect($bericht['groups'])->toBe([])
        ->and($bericht['uncategorized']['minutes'])->toBe(45);
});

/** Ein Termin MIT Kategorie gehört nicht in die Aufschlüsselung. */
it('schluesselt einen Termin mit Kategorie nicht zusaetzlich auf', function () {
    GroupedBlockKind::$nachTitel = ['Beides' => 'Projekt Nord'];

    berichtTermin(['title' => 'Beides'], ['Kundentermin']);

    $bericht = bericht();

    expect($bericht['groups'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Nach Terminart (#8307)
|--------------------------------------------------------------------------
|
| Diese Aufschluesselung gab es bis zum 19.10.2026 nur im Manager, und weil
| sie im Paket fehlte, konnte der Manager seine eigene Seite nicht gegen die
| geteilte tauschen, ohne seinen Nutzern etwas wegzunehmen.
|
| Der Unterschied zu `categories` steckt im Zaehlen: Ein Termin hat GENAU EINE
| Art, aber null bis viele Kategorien. Ueber die Arten summiert kommt deshalb
| die Gesamtzeit heraus — ueber die Kategorien summiert nicht.
*/

it('schluesselt nach Terminart auf', function () {
    berichtTermin(['title' => 'A', 'starts_at' => '2026-09-08 09:00', 'ends_at' => '2026-09-08 10:00'], ['Kundentermin']);
    blockTermin('Block', 90);

    $arten = collect(bericht()['kinds'])->keyBy('label');

    expect($arten)->toHaveCount(2)
        ->and($arten['Mit Kategorien']['minutes'])->toBe(60)
        ->and($arten['Mit Kategorien']['count'])->toBe(1)
        ->and($arten['Zeitblock mit Gruppe']['minutes'])->toBe(90);
});

it('zaehlt einen Termin mit zwei Kategorien bei seiner Art nur einmal', function () {
    berichtTermin(['title' => 'Doppelt'], ['Kundentermin', 'Vertrieb']);

    $ergebnis = bericht();
    $art = collect($ergebnis['kinds'])->firstWhere('label', 'Mit Kategorien');

    // Die Art einmal, die Kategorien beide voll — das ist der ganze
    // Unterschied zwischen den zwei Abschnitten.
    expect($art['count'])->toBe(1)
        ->and($art['minutes'])->toBe(60)
        ->and($art['categories'])->toHaveCount(2)
        ->and(collect($ergebnis['categories'])->sum('minutes'))->toBe(120);
});

it('ergibt ueber alle Arten summiert die Gesamtzeit', function () {
    berichtTermin(['title' => 'Eins'], ['Kundentermin', 'Vertrieb']);
    berichtTermin(['title' => 'Zwei', 'starts_at' => '2026-09-09 09:00', 'ends_at' => '2026-09-09 11:00'], []);
    blockTermin('Block', 30);

    $ergebnis = bericht();

    expect(collect($ergebnis['kinds'])->sum('minutes'))->toBe($ergebnis['total']['minutes'])
        ->and(collect($ergebnis['kinds'])->sum('count'))->toBe($ergebnis['total']['count']);
});

it('fuehrt eine Art ohne Kategorie mit leerer Unterliste', function () {
    blockTermin('Block', 45);

    $art = collect(bericht()['kinds'])->firstWhere('label', 'Zeitblock mit Gruppe');

    expect($art['minutes'])->toBe(45)
        ->and($art['categories'])->toBe([]);
});

it('stellt die laengste Art nach vorn', function () {
    berichtTermin(['title' => 'Kurz'], ['Kundentermin']);
    blockTermin('Lang', 300);

    expect(bericht()['kinds'][0]['label'])->toBe('Zeitblock mit Gruppe');
});
