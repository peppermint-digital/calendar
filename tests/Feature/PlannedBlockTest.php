<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Exceptions\IdentifierTooLong;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Schema\CalendarSchema;
use Peppermint\Calendar\Tests\Fixtures\DemoTask;
use Peppermint\Calendar\Tests\Fixtures\TaskBlockKind;
use Peppermint\Calendar\Tests\Fixtures\TaskBlockProfile;

/**
 * Der geplante Block als Form, nicht als Nachbau.
 *
 * Vier Systeme hatten diese Form, drei verschieden gebaut. Was hier geprueft
 * wird, ist das, was sie sich teilen koennen.
 */
beforeEach(function () {
    Schema::create('demo_tasks', function (Blueprint $table) {
        $table->id();
        $table->string('title');
    });

    CalendarSchema::plannedBlockProfile('task_block', 'demo_tasks');

    app(EventKindRegistry::class)->register(new TaskBlockKind);

    $this->aufgabe = DemoTask::create(['title' => 'Angebot schreiben']);
});

afterEach(function () {
    Schema::dropIfExists('calendar_event_profile_task_block');
    Schema::dropIfExists('demo_tasks');
});

function geplanterBlock(array $werte = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'task_block',
        'owner_id' => 1,
        'title' => 'Angebot schreiben',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ], $werte));
}

it('leitet das Profilfeld aus dem Gegenstand ab', function () {
    $art = new TaskBlockKind;

    // Keine vierte Entscheidung: Aus `DemoTask` wird `demo_task_id`.
    expect($art->subjectKey())->toBe('demo_task_id')
        ->and($art->profileFields())->toBe(['demo_task_id']);
});

it('bringt die drei Entscheidungen mit, die drei Systeme je selbst getroffen haben', function () {
    $art = new TaskBlockKind;

    expect($art->isUserCreatable())->toBeFalse()
        ->and($art->usesTrash())->toBeFalse();
});

it('nimmt den Gegenstand flach am Termin an und gibt ihn so zurueck', function () {
    $block = geplanterBlock(['demo_task_id' => $this->aufgabe->id]);

    expect(TaskBlockProfile::where('event_id', $block->id)->value('demo_task_id'))->toBe($this->aufgabe->id)
        ->and(CalendarEvent::findOrFail($block->id)->demo_task_id)->toBe($this->aufgabe->id);
});

it('fuehrt vom Profil zum Gegenstand und zurueck zum Termin', function () {
    $block = geplanterBlock(['demo_task_id' => $this->aufgabe->id]);

    $profil = TaskBlockProfile::where('event_id', $block->id)->sole();

    expect($profil->subject->title)->toBe('Angebot schreiben')
        ->and($profil->event->is($block))->toBeTrue();
});

it('nimmt die Planung mit, wenn der Termin geht — ohne Papierkorb', function () {
    $block = geplanterBlock(['demo_task_id' => $this->aufgabe->id]);

    $block->delete();

    expect(CalendarEvent::find($block->id))->toBeNull()
        ->and(TaskBlockProfile::where('event_id', $block->id)->exists())->toBeFalse();
});

it('leert den Zeiger, wenn der Gegenstand geht — der Block bleibt', function () {
    $block = geplanterBlock(['demo_task_id' => $this->aufgabe->id]);

    $this->aufgabe->delete();

    // `nullOnDelete` und nicht `cascadeOnDelete`: Ob der Termin mitgeht,
    // entscheidet die ART über ihren Speicher-Haken, nicht die Datenbank
    // hinter dem Rücken der Anwendung.
    expect(CalendarEvent::find($block->id))->not->toBeNull()
        ->and(TaskBlockProfile::where('event_id', $block->id)->value('demo_task_id'))->toBeNull();
});

it('laesst einen Termin kein zweites Profil bekommen', function () {
    $block = geplanterBlock(['demo_task_id' => $this->aufgabe->id]);

    // Konkrete Klasse, nicht `Throwable::class`: Das ist ein Interface, Pest
    // findet dafuer keine Klasse und vergleicht stattdessen die MELDUNG — eine
    // Zusicherung, die dann nichts prueft.
    expect(fn () => TaskBlockProfile::create(['event_id' => $block->id, 'demo_task_id' => $this->aufgabe->id]))
        ->toThrow(QueryException::class);
});

describe('die Bezeichnergrenze', function () {
    it('haelt eine Migration auf, deren Index zu lang wuerde', function () {
        // Genau der Fall, der am 13.10.2026 drei Deploys aufgehalten hat:
        // calendar_event_profile_resource_booking_resource_booking_id_foreign
        expect(fn () => CalendarSchema::plannedBlockProfile(
            'resource_booking',
            'resource_bookings',
            'resource_booking_id',
        ))->toThrow(IdentifierTooLong::class);

        expect(Schema::hasTable('calendar_event_profile_resource_booking'))->toBeFalse();
    });

    it('nennt in der Meldung den Ausweg, nicht nur die Zahl', function () {
        try {
            CalendarSchema::plannedBlockProfile('resource_booking', 'resource_bookings', 'resource_booking_id');
        } catch (IdentifierTooLong $e) {
            // Ein Handname fuer den Index rettet diese eine Stelle und laesst
            // die naechste Spalte wieder hineinlaufen. Die Meldung sagt das.
            expect($e->getMessage())->toContain('booking_id')
                ->and($e->getMessage())->toContain('SQLite does not enforce');

            return;
        }

        $this->fail('keine Ausnahme');
    });

    it('laesst den kurzen Namen durch', function () {
        CalendarSchema::plannedBlockProfile('resource_booking', 'demo_tasks', 'booking_id');

        expect(Schema::hasColumn('calendar_event_profile_resource_booking', 'booking_id'))->toBeTrue();

        Schema::drop('calendar_event_profile_resource_booking');
    });
});

describe('der Satellit', function () {
    afterEach(fn () => Schema::dropIfExists('calendar_event_notes'));

    it('haengt an jeder Terminart, ohne dass eine davon weiss', function () {
        CalendarSchema::satellite('calendar_event_notes', function (Blueprint $table) {
            $table->text('body');
        });

        $block = geplanterBlock(['demo_task_id' => $this->aufgabe->id]);

        DB::table('calendar_event_notes')->insert([
            'event_id' => $block->id,
            'body' => 'Angebot liegt im Postfach',
        ]);

        expect(DB::table('calendar_event_notes')->where('event_id', $block->id)->count())->toBe(1);
    });

    it('darf mehrfach am selben Termin haengen', function () {
        CalendarSchema::satellite('calendar_event_notes', fn (Blueprint $table) => $table->text('body'));

        $block = geplanterBlock();

        // Kein eindeutiger Index auf `event_id`: drei Teilnehmer, zwei Notizen,
        // fuenf Tags. Wer 1:1 braucht, setzt den Index selbst.
        DB::table('calendar_event_notes')->insert([
            ['event_id' => $block->id, 'body' => 'erste'],
            ['event_id' => $block->id, 'body' => 'zweite'],
        ]);

        expect(DB::table('calendar_event_notes')->where('event_id', $block->id)->count())->toBe(2);
    });

    it('faellt mit dem Termin', function () {
        CalendarSchema::satellite('calendar_event_notes', fn (Blueprint $table) => $table->text('body'));

        $block = geplanterBlock();
        DB::table('calendar_event_notes')->insert(['event_id' => $block->id, 'body' => 'weg damit']);

        $block->delete();

        expect(DB::table('calendar_event_notes')->count())->toBe(0);
    });
});
