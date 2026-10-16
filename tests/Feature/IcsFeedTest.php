<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Http\CalendarRoutes;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;
use Peppermint\Calendar\Tests\Fixtures\DemoUser;

/**
 * Der ICS-Feed — die einzige nicht angemeldete Adresse des Kalenders (#7846).
 *
 * Hier wird nicht geprüft, dass eine Datei herauskommt. Geprüft wird, was
 * NICHT herauskommt: fremde Termine, Termine ausserhalb des Fensters, und
 * irgendetwas bei einem Token, der keiner ist. Ein Feed, der zu viel
 * ausliefert, sieht genauso aus wie einer, der richtig arbeitet — das ist der
 * Grund, warum diese Datei ausführlich ist.
 */
beforeEach(function () {
    config()->set('calendar.user_model', DemoUser::class);
    config()->set('calendar.sources', [DemoSource::class]);
    DemoSource::$available = true;

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('calendar_token', 64)->nullable();
        });
    }

    CalendarRoutes::feed();
});

/** Ein Token von der Länge, die die vier Systeme erzeugen. */
function feedToken(string $zeichen = 'a'): string
{
    return str_repeat($zeichen, 64);
}

function feedPerson(?string $token = null): DemoUser
{
    return DemoUser::create([
        'name' => 'Volker',
        'calendar_token' => $token ?? feedToken(),
    ]);
}

function feedTermin(DemoUser $person, array $werte = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => $person->id,
        'title' => 'Jahresgespräch',
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(10, 0),
    ], $werte));
}

it('liefert die eigenen Termine als Kalenderdatei', function () {
    $person = feedPerson();
    feedTermin($person);

    $antwort = $this->get('/calendar/feed/'.feedToken().'.ics');

    $antwort->assertOk();
    expect($antwort->headers->get('Content-Type'))->toContain('text/calendar')
        ->and($antwort->getContent())->toContain('BEGIN:VCALENDAR')
        ->and($antwort->getContent())->toContain('Jahresgespräch');
});

/**
 * `inline`, nicht `attachment`: Ein Abonnement wird abgerufen, nicht
 * heruntergeladen. Mit `attachment` legen manche Programme bei JEDER
 * Aktualisierung eine Datei im Download-Ordner ab.
 */
it('wird abgerufen und nicht heruntergeladen', function () {
    feedPerson();

    expect($this->get('/calendar/feed/'.feedToken().'.ics')->headers->get('Content-Disposition'))
        ->toStartWith('inline');
});

/** Ein Kalenderprogramm fragt alle paar Minuten — ein Zwischenspeicher zeigt Vergangenes. */
it('laesst sich nicht zwischenspeichern', function () {
    feedPerson();

    expect($this->get('/calendar/feed/'.feedToken().'.ics')->headers->get('Cache-Control'))
        ->toContain('no-store');
});

describe('wer nichts bekommt', function () {
    it('gibt 404 bei einem unbekannten Token', function () {
        feedPerson();

        $this->get('/calendar/feed/'.feedToken('b').'.ics')->assertNotFound();
    });

    /**
     * Der Riegel VOR der Datenbank — gegen einen Token, der nur aus Leerzeichen
     * besteht.
     *
     * `/calendar/feed/.ics` wäre der naheliegende Fall, prüft aber nichts: Den
     * fängt schon das Routing ab, weil ein Platzhalter nicht leer sein darf.
     * Durchkommen würde Weissraum — lang genug für jede Längenprüfung und nach
     * `trim()` leer. Ohne den Riegel wäre das eine Abfrage mit leerem Wert, und
     * ein Produkt, das in der Spalte versehentlich einen leeren String stehen
     * lässt, liefert dann dessen Kalender an jeden aus.
     */
    it('weist einen Token aus Weissraum ab, bevor die Datenbank gefragt wird', function () {
        $person = feedPerson('');
        feedTermin($person, ['title' => 'Darf niemand sehen']);

        $antwort = $this->get('/calendar/feed/'.str_repeat('%20', 40).'.ics');

        $antwort->assertNotFound();
        expect($antwort->getContent())->not->toContain('Darf niemand sehen');
    });

    it('weist einen zu kurzen Token ab', function () {
        $person = feedPerson('kurz');
        feedTermin($person, ['title' => 'Darf niemand sehen']);

        $this->get('/calendar/feed/kurz.ics')->assertNotFound();
    });

    /**
     * 404 und nicht 403.
     *
     * Dass ein gültiger Token 200 und ein falscher 404 gibt, ist unvermeidlich
     * — darum geht es nicht. Es geht um den Unterschied zwischen „gibt es
     * nicht" und „gibt es, darfst du aber nicht": Ein 403 wäre die Bestätigung,
     * dass dieser Token existiert, und damit ein Orakel zum Durchprobieren.
     */
    it('antwortet auf einen falschen Token mit 404 und nicht mit 403', function () {
        feedPerson();

        $antwort = $this->get('/calendar/feed/'.feedToken('c').'.ics');

        expect($antwort->status())->toBe(404)->and($antwort->status())->not->toBe(403);
    });
});

describe('was im Feed steht', function () {
    it('zeigt den Termin eines anderen nicht', function () {
        feedPerson();
        $andere = DemoUser::create(['name' => 'Andere', 'calendar_token' => feedToken('x')]);
        feedTermin($andere, ['title' => 'Fremder Termin']);

        expect($this->get('/calendar/feed/'.feedToken().'.ics')->getContent())
            ->not->toContain('Fremder Termin');
    });

    /**
     * `visibleTo` heisst Eigentum ODER Teilnahme — dasselbe, was diese Person
     * in ihrem Kalender sieht. Ein Feed ist keine Gelegenheit, Rechte neu zu
     * erfinden.
     */
    it('zeigt einen Termin, zu dem man eingeladen ist', function () {
        $person = feedPerson();
        $andere = DemoUser::create(['name' => 'Andere', 'calendar_token' => feedToken('x')]);

        $termin = feedTermin($andere, ['title' => 'Bin eingeladen']);
        $termin->attendees()->create(['user_id' => $person->id, 'status' => 'accepted']);

        expect($this->get('/calendar/feed/'.feedToken().'.ics')->getContent())
            ->toContain('Bin eingeladen');
    });

    /** Rückwärts begrenzt: Ein Abonnement ist kein Archiv. */
    it('laesst lange Vergangenes weg', function () {
        $person = feedPerson();
        feedTermin($person, [
            'title' => 'Vor zwei Jahren',
            'starts_at' => now()->subYears(2),
            'ends_at' => now()->subYears(2)->addHour(),
        ]);

        expect($this->get('/calendar/feed/'.feedToken().'.ics')->getContent())
            ->not->toContain('Vor zwei Jahren');
    });

    it('laesst sich das Fenster vom Produkt sagen', function () {
        config()->set('calendar.feed.past_days', 1000);

        $person = feedPerson();
        feedTermin($person, [
            'title' => 'Vor zwei Jahren',
            'starts_at' => now()->subYears(2),
            'ends_at' => now()->subYears(2)->addHour(),
        ]);

        expect($this->get('/calendar/feed/'.feedToken().'.ics')->getContent())
            ->toContain('Vor zwei Jahren');
    });

    /**
     * Fremde Kalender bleiben draussen. Sie liegen dort, sind dort
     * abonnierbar, und ein Feed, der bei jedem Abruf drei Fremdsysteme
     * befragt, ist langsam und fällt mit ihnen aus.
     */
    it('befragt keine fremden Systeme', function () {
        feedPerson();

        expect($this->get('/calendar/feed/'.feedToken().'.ics')->getContent())
            ->not->toContain('Meeting elsewhere');
    });

    /** Die Spalte heisst nicht überall gleich. */
    it('liest die Spalte, die das Produkt nennt', function () {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ics_token', 64)->nullable();
        });

        config()->set('calendar.feed.token_column', 'ics_token');

        $person = DemoUser::create(['name' => 'Volker', 'ics_token' => feedToken('d')]);
        feedTermin($person, ['title' => 'Andere Spalte']);

        expect($this->get('/calendar/feed/'.feedToken('d').'.ics')->getContent())
            ->toContain('Andere Spalte');
    });
});

/**
 * Der Token selbst — erzeugt wird er vom Produkt, aber nach einer Regel.
 *
 * Vier Systeme, die ihn jeweils selbst erzeugen, sind vier Gelegenheiten, ihn
 * zu kurz oder zu vorhersagbar zu nehmen. Und zwar unauffällig: Ein schwacher
 * Token funktioniert genauso gut wie ein starker.
 */
describe('der Token', function () {
    it('ist lang genug fuer den Riegel des Feeds', function () {
        expect(strlen(Peppermint\Calendar\Support\FeedToken::make()))->toBe(64)
            ->and(Peppermint\Calendar\Support\FeedToken::acceptable(Peppermint\Calendar\Support\FeedToken::make()))
            ->toBeTrue();
    });

    it('ist jedes Mal ein anderer', function () {
        expect(Peppermint\Calendar\Support\FeedToken::make())
            ->not->toBe(Peppermint\Calendar\Support\FeedToken::make());
    });

    it('nennt kurz, leer und fehlend untauglich', function () {
        expect(Peppermint\Calendar\Support\FeedToken::acceptable(null))->toBeFalse()
            ->and(Peppermint\Calendar\Support\FeedToken::acceptable(''))->toBeFalse()
            ->and(Peppermint\Calendar\Support\FeedToken::acceptable('kurz'))->toBeFalse()
            ->and(Peppermint\Calendar\Support\FeedToken::acceptable(str_repeat(' ', 64)))->toBeFalse();
    });

    /** Ein frischer Token muss durch den Feed kommen, nicht nur durch die Regel. */
    it('kommt durch den Feed', function () {
        $token = Peppermint\Calendar\Support\FeedToken::make();
        $person = feedPerson($token);
        feedTermin($person, ['title' => 'Mit frischem Token']);

        expect($this->get('/calendar/feed/'.$token.'.ics')->getContent())
            ->toContain('Mit frischem Token');
    });
});
