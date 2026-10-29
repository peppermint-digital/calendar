<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Services\AgendaAssembler;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;

/**
 * Die Zeilen der Agenda (#7846).
 *
 * „Was kommt als Nächstes" war am 16.10.2026 in zwei Systemen gebaut und in
 * zwei nicht. Die beiden Fassungen unterschieden sich nicht in der Absicht,
 * sondern in Kleinigkeiten — und genau die prüft diese Datei.
 */
beforeEach(function () {
    DemoSource::$available = false;
    DemoSource::$throws = false;
    DemoSource::$extra = [];

    config()->set('calendar.sources', [DemoSource::class]);
    app()->forgetInstance(EventSourceRegistry::class);

    CarbonImmutable::setTestNow('2026-09-08 08:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function agendaTermin(array $werte = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Termin',
        'starts_at' => '2026-09-08 10:00',
        'ends_at' => '2026-09-08 11:00',
    ], $werte));
}

it('nimmt nur Termine im Fenster', function () {
    agendaTermin(['title' => 'Heute']);
    agendaTermin(['title' => 'In drei Monaten', 'starts_at' => '2026-12-08 10:00', 'ends_at' => '2026-12-08 11:00']);

    $zeilen = app(AgendaAssembler::class)->rows(1, days: 14);

    expect(collect($zeilen)->pluck('title')->all())->toBe(['Heute']);
});

/**
 * `visibleTo` heisst Eigentum ODER Teilnahme. Ohne diese Prüfung wäre die
 * Agenda die Ansicht, in der ein Termin fehlt, zu dem man eingeladen ist.
 */
it('laesst fremde Termine weg, zu denen man nicht gehoert', function () {
    agendaTermin(['title' => 'Meiner', 'owner_id' => 1]);
    agendaTermin(['title' => 'Fremder', 'owner_id' => 2]);

    expect(collect(app(AgendaAssembler::class)->rows(1))->pluck('title')->all())->toBe(['Meiner']);
});

/**
 * Ganztägige Einträge stehen vor den zeitgebundenen desselben Tages: Sie
 * gelten für den ganzen Tag, also auch für die erste Stunde.
 */
it('stellt den ganzen Tag vor die Uhrzeit', function () {
    agendaTermin(['title' => 'Um zehn']);
    agendaTermin([
        'title' => 'Den ganzen Tag',
        'all_day' => true,
        'starts_at' => '2026-09-08 00:00',
        'ends_at' => '2026-09-08 23:59',
    ]);

    expect(collect(app(AgendaAssembler::class)->rows(1))->pluck('title')->all())
        ->toBe(['Den ganzen Tag', 'Um zehn']);
});

it('sagt bei ganztaegig keine Uhrzeit', function () {
    agendaTermin([
        'all_day' => true,
        'starts_at' => '2026-09-08 00:00',
        'ends_at' => '2026-09-08 23:59',
    ]);

    $zeile = app(AgendaAssembler::class)->rows(1)[0];

    expect($zeile['start'])->toBeNull()->and($zeile['end'])->toBeNull();
});

/** Das Fenster ist gedeckelt, sonst wird die Liste ein zweiter Kalender. */
it('deckelt das Fenster auf 90 Tage und mindestens einen', function () {
    [, , $gedeckelt] = app(AgendaAssembler::class)->window(365);
    [, , $mindestens] = app(AgendaAssembler::class)->window(0);

    expect($gedeckelt)->toBe(90)->and($mindestens)->toBe(1);
});

describe('fremde Kalender', function () {
    beforeEach(function () {
        DemoSource::$available = true;
    });

    it('nimmt sie mit auf', function () {
        $zeilen = app(AgendaAssembler::class)->rows(1, days: 14);

        expect(collect($zeilen)->pluck('title')->all())->toContain('Meeting elsewhere');
    });

    /**
     * Das Etikett nennt den NAMEN des Systems, nicht seinen Schlüssel:
     * `ExternalEvent::toArray()` trägt nur den Schlüssel, und „demo" ist kein
     * Etikett, das jemand lesen will.
     */
    it('nennt das System mit seinem Namen', function () {
        $fremd = collect(app(AgendaAssembler::class)->rows(1))
            ->firstWhere('title', 'Meeting elsewhere');

        expect($fremd['badge'])->toBe('Demo system');
    });

    /**
     * Dasselbe Vorzeichen wie in der Filterleiste. Stünde die Regel hier
     * anders, wäre die Agenda die Ansicht, in der ein abgeschalteter Kalender
     * wieder auftaucht.
     */
    it('laesst eine abgeschaltete Quelle weg', function () {
        $zeilen = app(AgendaAssembler::class)->rows(1, hidden: ['source:demo']);

        expect(collect($zeilen)->pluck('title')->all())->not->toContain('Meeting elsewhere');
    });

    /** Eine versteckte Art geht genauso weg wie eine versteckte Quelle. */
    it('laesst eine abgeschaltete Art weg', function () {
        agendaTermin(['title' => 'Eigener']);

        $zeilen = app(AgendaAssembler::class)->rows(1, hidden: ['business']);

        expect(collect($zeilen)->pluck('title')->all())->not->toContain('Eigener');
    });
});

/**
 * Die Zeile trägt KEINE Farbe: Die Farbtafel liegt im Produkt, und eine zweite
 * im Paket wäre die, die irgendwann abweicht. Sie trägt ihre Art, damit das
 * Produkt die Farbe mit derselben Tafel nachsetzt, die auch das Raster färbt.
 */
it('nennt die Art und faerbt nicht selbst', function () {
    agendaTermin();

    $zeile = app(AgendaAssembler::class)->rows(1)[0];

    expect($zeile['kind'])->toBe('business')->and($zeile['colour'])->toBeNull();
});

/**
 * Woran der Termin hängt — und wohin ein fremder führt.
 *
 * Beides stand bis zum 29.10.2026 nicht in der Zeile, und beides ist ein
 * Begriff dieses Pakets: `subject_type`/`subject_id` sind Kernspalten, und
 * `ExternalEvent::$url` ist ein Feld des Pakets.
 *
 * Die Folge: Jedes Produkt, das von der Agenda aus zur Sache springen wollte,
 * holte sich die Angaben selbst aus den Rohdaten — AI Brain tat es in
 * TypeScript, neben der Zeile, die das Paket schon gebaut hatte. Das ist die
 * dritte Fassung derselben Abbildung.
 */
it('nennt den Gegenstand eines eigenen Termins', function () {
    agendaTermin(['subject_type' => 'task', 'subject_id' => 77]);

    $zeile = collect(app(AgendaAssembler::class)->rows(1))->firstWhere('title', 'Termin');

    expect($zeile['subject_type'])->toBe('task')
        ->and($zeile['subject_id'])->toBe(77);
});

it('laesst den Gegenstand leer, wenn der Termin an nichts haengt', function () {
    // `null` ist hier die Antwort und kein Fehlen: Ein Termin ohne Gegenstand
    // ist der Normalfall, und das Produkt muss die beiden Faelle
    // unterscheiden koennen, ohne nachzufragen.
    agendaTermin();

    $zeile = collect(app(AgendaAssembler::class)->rows(1))->firstWhere('title', 'Termin');

    expect($zeile)->toHaveKey('subject_type')
        ->and($zeile['subject_type'])->toBeNull()
        ->and($zeile['subject_id'])->toBeNull();
});

it('nennt bei einem fremden Termin den Weg in sein Heimatsystem', function () {
    // Ein fremder Termin hat hier keine Einzelansicht. Ohne diese Adresse
    // bleibt der Klick auf die Zeile ohne Ziel — oder das Produkt holt sie
    // sich selbst, und dann gibt es die Abbildung zweimal.
    DemoSource::$available = true;

    $zeile = collect(app(AgendaAssembler::class)->rows(1))->firstWhere('title', 'Meeting elsewhere');

    expect($zeile['url'])->toBe('https://elsewhere.test/events/42');
});
