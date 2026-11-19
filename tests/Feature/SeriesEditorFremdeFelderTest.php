<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Exceptions\AttributeWithoutHome;
use Peppermint\Calendar\Exceptions\ForbiddenAttributeForKind;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Recurrence\SeriesEditor;
use Peppermint\Calendar\Tests\Fixtures\BusinessProfile;

/**
 * Was beim Abteilen einer Serie mitkommt — und was nicht (Bug #1519).
 *
 * Gemessen am 19.11.2026 im Manager: „nur dieser" und „dieser und kuenftige"
 * an einer Zeitblock-Serie endeten im 500, auch ohne `is_not_billable` im
 * Aufruf. Der Stacktrace endete in diesem Paket, die Ursache lag nicht hier:
 * Der Manager reichte `is_not_billable => false` als Aenderung herein.
 *
 * Diese Datei haelt die Mechanik dort fest, wo sie wohnt:
 *
 *   1. `SeriesEditor` uebernimmt vom Master nur feste Kernfelder. Ein Feld
 *      der Art — Profil oder sonst — kommt nur mit, wenn der Aufrufer es in
 *      `$changes` reicht.
 *   2. Was der Aufrufer reicht, gilt als geschickt und durchlaeuft dieselben
 *      Riegel wie jedes andere Speichern. `false` ist dabei BELEGT
 *      (`filled(false)` ist wahr) — wer ein bool reicht, das die Art nicht
 *      fuehrt, bekommt eine Ausnahme, kein stilles Weglassen.
 *   3. `null` dagegen faellt still weg: Ein leerer Wert sagt nichts aus.
 *
 * Punkt 2 ist gewollt (#1503: still verworfene Werte sind Datenverlust). Die
 * Abhilfe liegt beim Aufrufer — er schickt nur, was die Zielart fuehrt.
 */
function zeitblockArt(): void
{
    app(EventKindRegistry::class)->register(new class extends EventKind
    {
        public function key(): string
        {
            return 'zeitblock';
        }

        public function label(): string
        {
            return 'Zeitblock';
        }

        public function forbiddenAttributes(): array
        {
            return ['is_not_billable'];
        }
    });
}

function wochenserie(string $art, array $mehr = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => $art,
        'owner_id' => 1,
        'title' => 'Jour Fixe',
        'starts_at' => '2026-09-01 09:00:00',
        'ends_at' => '2026-09-01 10:00:00',
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['TU']],
    ], $mehr));
}

/** @return callable(CalendarEvent, array<string, mixed>): CalendarEvent */
function abteilen(string $weg): callable
{
    $stichtag = CarbonImmutable::parse('2026-09-15 09:00');

    return match ($weg) {
        'detach' => fn (CalendarEvent $serie, array $changes) => app(SeriesEditor::class)->detachOccurrence($serie, $stichtag, $changes),
        'split' => fn (CalendarEvent $serie, array $changes) => app(SeriesEditor::class)->splitSeries($serie, $stichtag, $changes),
    };
}

dataset('wege', ['detach', 'split']);

it('teilt eine Serie ab, wenn der Aufrufer kein fremdes Feld reicht', function (string $weg) {
    zeitblockArt();
    $serie = wochenserie('zeitblock');

    $neu = abteilen($weg)($serie, ['title' => 'Nachher']);

    expect($neu->exists)->toBeTrue()
        ->and($neu->id)->not->toBe($serie->id)
        ->and($neu->title)->toBe('Nachher')
        ->and(CalendarEvent::count())->toBe(2);
})->with('wege');

it('wirft, wenn der Aufrufer ein verbotenes Feld als false reicht', function (string $weg) {
    // Genau die Lage aus #1519: `false` ist fuer `filled()` belegt.
    zeitblockArt();
    $serie = wochenserie('zeitblock');

    expect(fn () => abteilen($weg)($serie, ['is_not_billable' => false]))
        ->toThrow(ForbiddenAttributeForKind::class);

    // Keine zweite Zeile, und die Serie behaelt ihr Ende.
    expect(CalendarEvent::count())->toBe(1)
        ->and($serie->fresh()->recurrence_until)->toBeNull();
})->with('wege');

it('wirft, wenn der Aufrufer ein heimatloses Feld als bool reicht', function (string $weg) {
    // `private` hat weder eine Kernspalte noch ein Profilfeld dafuer.
    $serie = wochenserie('private');

    expect(fn () => abteilen($weg)($serie, ['is_not_billable' => true]))
        ->toThrow(AttributeWithoutHome::class);

    expect(CalendarEvent::count())->toBe(1);
})->with('wege');

it('laesst ein heimatloses Feld still weg, wenn es leer ist', function (string $weg) {
    // Die Gegenseite: `null` sagt nichts aus und geht nicht verloren.
    $serie = wochenserie('private');

    $neu = abteilen($weg)($serie, ['is_not_billable' => null, 'title' => 'Nachher']);

    expect($neu->exists)->toBeTrue()
        ->and(CalendarEvent::count())->toBe(2);
})->with('wege');

it('nimmt ein Feld mit, das die Art fuehrt, wenn der Aufrufer es reicht', function (string $weg) {
    // Die Gegenprobe: Die Riegel duerfen nicht mehr wegnehmen als das
    // Heimatlose. `business` fuehrt beide Felder im Profil.
    $serie = wochenserie('business', ['project_id' => 7, 'is_not_billable' => true]);

    $neu = abteilen($weg)($serie, ['project_id' => 7, 'is_not_billable' => true]);

    $profil = BusinessProfile::where('event_id', $neu->id)->sole();

    expect($profil->project_id)->toBe(7)
        ->and((bool) $profil->is_not_billable)->toBeTrue();
})->with('wege');

it('uebernimmt Profilfelder des Masters NICHT von selbst', function (string $weg) {
    // Heutiges Verhalten, festgehalten statt angenommen: Das Paket kopiert
    // nur feste Kernfelder. Wer Profilfelder am abgeteilten Termin will,
    // reicht sie in `$changes` — deshalb baut der Manager seine Liste
    // `$eigene`. Ob das Paket das kuenftig selbst tun soll, ist eine offene
    // Frage (#1519, vertagt), nicht Teil dieser Behebung.
    $serie = wochenserie('business', ['project_id' => 7]);

    $neu = abteilen($weg)($serie, []);

    expect(CalendarEvent::findOrFail($neu->id)->project_id)->toBeNull();
})->with('wege');
