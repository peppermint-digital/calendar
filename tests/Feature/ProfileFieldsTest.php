<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Exceptions\ProfileFieldsWithoutProfile;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Tests\Fixtures\BusinessProfile;

/**
 * Felder der Art duerfen flach am Termin uebergeben werden.
 *
 * Das ist der Weg, auf dem ein Feld die geteilte Kerntabelle verlassen kann,
 * ohne dass jede Lese- und Schreibstelle der Anwendung mitwandern muss.
 */
function termin(array $werte = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Jahresplanung',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ], $werte));
}

it('schreibt ein flach uebergebenes Feld ins Profil', function () {
    $termin = termin(['project_id' => 7, 'is_not_billable' => true]);

    $profil = BusinessProfile::where('event_id', $termin->id)->sole();

    expect($profil->project_id)->toBe(7)
        ->and((bool) $profil->is_not_billable)->toBeTrue();
});

it('liest das Feld wieder, als saesse es am Termin', function () {
    $id = termin(['project_id' => 7])->id;

    // Frisch aus der Datenbank, damit nichts aus dem Arbeitsspeicher
    // mitgenommen wird: Genau so liest es jede Stelle der Anwendung.
    expect(CalendarEvent::findOrFail($id)->project_id)->toBe(7);
});

it('nimmt eine Aenderung ins Profil mit, ohne eine zweite Zeile anzulegen', function () {
    $termin = termin(['project_id' => 7]);

    $termin->update(['project_id' => 9]);

    expect(BusinessProfile::where('event_id', $termin->id)->count())->toBe(1)
        ->and(CalendarEvent::findOrFail($termin->id)->project_id)->toBe(9);
});

it('legt gar kein Profil an, wenn kein Profilfeld uebergeben wurde', function () {
    $termin = termin();

    expect(BusinessProfile::where('event_id', $termin->id)->exists())->toBeFalse()
        ->and($termin->project_id)->toBeNull();
});

it('bekommt sein Profil auch nachtraeglich, wenn es vor dem Umzug ohne entstand', function () {
    $termin = termin();

    $termin->update(['project_id' => 4]);

    expect(BusinessProfile::where('event_id', $termin->id)->value('project_id'))->toBe(4);
});

it('laesst Felder einer Art ohne Profil nicht ins Leere laufen', function () {
    $art = new class extends EventKind
    {
        public function key(): string
        {
            return 'ohne_profil';
        }

        public function label(): string
        {
            return 'Ohne Profil';
        }

        public function profileFields(): array
        {
            return ['project_id'];
        }
    };

    app(EventKindRegistry::class)->register($art);

    expect(fn () => termin(['kind' => 'ohne_profil', 'project_id' => 3]))
        ->toThrow(ProfileFieldsWithoutProfile::class);
});

it('wirft nicht, wenn die Art leer oder unbekannt ist', function () {
    $termin = termin(['project_id' => 7]);

    // Beides kommt in gewachsenen Anwendungen vor: ein Formular schickt eine
    // leere Art mit, und eine Art verschwindet aus der Registrierung, waehrend
    // ihre Zeilen stehen bleiben. Ein LESEZUGRIFF darf daran nicht scheitern —
    // beim Speichern ist der Abbruch richtig, hier waere er eine Ausnahme aus
    // einem Getter, der nur ein Feld liefern sollte.
    foreach (['', 'gibt_es_nicht'] as $art) {
        $halb = CalendarEvent::findOrFail($termin->id);
        $halb->setRawAttributes(['id' => $termin->id, 'kind' => $art] + $halb->getAttributes(), true);

        expect(fn () => $halb->project_id)->not->toThrow(Throwable::class);
    }
});

it('wirft nicht, wenn die Zeile nur halb geladen ist', function () {
    termin(['project_id' => 7]);

    // Ohne `kind` in der Spaltenliste kann der Zugriffspfad das Profil nicht
    // finden. Die richtige Antwort ist `null`, nicht eine Ausnahme — und wer
    // so liest, muss wissen, dass er das Feld damit verliert.
    $schmal = CalendarEvent::query()->select(['id', 'title'])->firstOrFail();

    expect(fn () => $schmal->project_id)->not->toThrow(Throwable::class)
        ->and($schmal->project_id)->toBeNull();
});

it('uebersteht ein refresh, nachdem ein Profilfeld gelesen wurde', function () {
    $termin = termin(['project_id' => 7]);

    // Das gemerkte Profil darf NICHT in den Beziehungen liegen: `refresh()`
    // laedt jede geladene Beziehung nach und baut sie auf einer leeren
    // Modellinstanz — ohne Terminart, also ohne Profilmodell. Drei Tests im
    // Manager sind genau daran umgefallen, an einem `refresh()` im Controller.
    expect($termin->project_id)->toBe(7);

    expect(fn () => $termin->refresh())->not->toThrow(Throwable::class)
        ->and($termin->project_id)->toBe(7);
});

it('liest nach einer Aenderung den neuen Wert, nicht den gemerkten', function () {
    $termin = termin(['project_id' => 7]);
    expect($termin->project_id)->toBe(7);

    $termin->update(['project_id' => 9]);

    expect($termin->project_id)->toBe(9)
        ->and(CalendarEvent::findOrFail($termin->id)->project_id)->toBe(9);
});

describe('solange die Kernspalte noch steht', function () {
    beforeEach(function () {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable();
        });

        CalendarEvent::forgetColumns();
    });

    afterEach(function () {
        Schema::table('calendar_events', fn (Blueprint $table) => $table->dropColumn('project_id'));

        CalendarEvent::forgetColumns();
    });

    it('schreibt in beide und liest aus dem Kern', function () {
        $termin = termin(['project_id' => 7]);

        // Der Wert steht an BEIDEN Stellen: Ein Rueckbau der Anwendung findet
        // ihn dann dort vor, wo der alte Code ihn sucht.
        expect(CalendarEvent::whereKey($termin->id)->value('project_id'))->toBe(7)
            ->and(BusinessProfile::where('event_id', $termin->id)->value('project_id'))->toBe(7)
            ->and(CalendarEvent::findOrFail($termin->id)->project_id)->toBe(7);
    });

    it('beantwortet die Frage weiter aus dem Kern, wenn nur dort etwas steht', function () {
        $termin = termin();
        CalendarEvent::whereKey($termin->id)->update(['project_id' => 5]);

        // Die Zeile von vor dem Umzug: Kernspalte gefuellt, kein Profil.
        expect(BusinessProfile::where('event_id', $termin->id)->exists())->toBeFalse()
            ->and(CalendarEvent::findOrFail($termin->id)->project_id)->toBe(5);
    });
});
