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

it('bringt die Farbe der fremden Quelle gleich mit', function () {
    /*
     * Hier stand bis zum 29.10.2026 `$daten['colour'] ?? null` — und das war
     * IMMER null: Weder `ExternalEvent::toArray()` noch das Quellenverzeichnis
     * trägt eine Farbe, die Quelle selbst kennt sie.
     *
     * Gemerkt hat es niemand, weil jedes Produkt die Farbe ohnehin selbst
     * nachgesucht hat — AI Brain mit einem `sources.find()` je Zeile im
     * Frontend. Ein Feld, das immer leer ist, erzeugt genau diese Art
     * Doppelarbeit: Es sieht benutzt aus.
     *
     * Das ist nicht derselbe Fall wie bei den EIGENEN Zeilen: Dort bleibt
     * `colour` mit Absicht leer, weil die Farbtafel der Terminarten im Produkt
     * liegt. Eine Quelle dagegen beschreibt sich selbst — sie HAT eine Farbe,
     * und die gehört in die Zeile.
     */
    DemoSource::$available = true;

    $zeile = collect(app(AgendaAssembler::class)->rows(1))->firstWhere('title', 'Meeting elsewhere');

    expect($zeile['colour'])->toBe('#123456');
});

describe('die Zone, in der die Uhrzeit steht', function () {
    /*
     * `rows()` nahm bis zum 29.10.2026 eine Zone und benutzte sie nur fuer das
     * FENSTER. Formatiert wurde in der Zone der Anwendung — die Agenda suchte
     * also die richtigen Termine und schrieb die falsche Uhrzeit daneben.
     *
     * Gefunden in AI Brain: Dort schreibt der Kalender-Speicher ausdruecklich
     * in Europe/Berlin, die Anwendung laeuft auf UTC. Ein Termin um 09:00 stand
     * in der Agenda als 08:00 — und zwar erst, nachdem die Agenda dort auf das
     * Paket umgestellt wurde. Vorher hat das Produkt selbst formatiert und es
     * richtig gemacht; die Umstellung haette eine Verschlechterung gebracht,
     * wenn der Test sie nicht gefangen haette.
     */
    it('formatiert in der gewuenschten Zone, nicht in der der Anwendung', function () {
        // 09:00 Berliner Zeit im Oktober ist 08:00 UTC.
        agendaTermin([
            'title' => 'Berliner Termin',
            'starts_at' => '2026-09-08 07:00',
            'ends_at' => '2026-09-08 08:00',
        ]);

        $zeile = collect(app(AgendaAssembler::class)->rows(1, timezone: 'Europe/Berlin'))
            ->firstWhere('title', 'Berliner Termin');

        // 07:00 UTC = 09:00 in Berlin (Sommerzeit).
        expect($zeile['start'])->toBe('09:00')
            ->and($zeile['end'])->toBe('10:00');
    });

    it('laesst ohne Zonenangabe alles wie es war', function () {
        // Die Bedingung dafuer, dass CRM und Verwaltung von dem Fix nichts
        // merken: Sie geben keine Zone mit, und bei ihnen schreibt und liest
        // alles in derselben.
        agendaTermin([
            'title' => 'Ohne Zone',
            'starts_at' => '2026-09-08 07:00',
            'ends_at' => '2026-09-08 08:00',
        ]);

        $zeile = collect(app(AgendaAssembler::class)->rows(1))->firstWhere('title', 'Ohne Zone');

        expect($zeile['start'])->toBe('07:00');
    });

    it('haengt die Zeile unter die Tagesueberschrift der gewuenschten Zone', function () {
        // Der Fall, den eine reine Uhrzeit-Umrechnung uebersieht: 00:30
        // Berliner Zeit liegt in UTC am Tag DAVOR. Wer nur die Stunde dreht,
        // schreibt die richtige Uhrzeit unter den falschen Tag.
        agendaTermin([
            'title' => 'Kurz nach Mitternacht',
            'starts_at' => '2026-09-08 22:30',
            'ends_at' => '2026-09-08 23:30',
        ]);

        $zeile = collect(app(AgendaAssembler::class)->rows(1, timezone: 'Europe/Berlin'))
            ->firstWhere('title', 'Kurz nach Mitternacht');

        expect($zeile['date'])->toBe('2026-09-09')
            ->and($zeile['start'])->toBe('00:30');
    });
});

/*
| Hier stand bis zum 29.10.2026 eine Pruefung, dass ein Produkt die
| Sichtbarkeits-Abfrage ersetzen kann (`AgendaSichtbarkeit`, v0.92.0).
|
| Der Vertrag ist in v0.95.0 wieder entfallen, weil er keinen Abrufer bekam:
| Der Manager-Agenda-Controller sollte auf diesen Zusammensteller wechseln und
| tut es nicht — seine Seite braucht an jedem Termin Felder, die es im Paket
| nicht gibt und nicht geben soll (Projekt, Aufgabe, Kategorie, Aufgabenstatus).
|
| Die Pruefung geht mit, und zwar absichtlich: Ein Test fuer eine Schnittstelle,
| die es nicht mehr gibt, ist kein Schutz, sondern eine Behauptung ueber eine
| Faehigkeit. Die Begruendung der Form steht im Kopf von
| `AgendaAssembler::eigeneAbfrage()`, damit sie nicht verloren geht.
*/

