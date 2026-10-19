<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Services\EventDetailPresenter;
use Peppermint\Calendar\Tests\Fixtures\DemoUser;

/**
 * Ein Termin in der Form, die die Einzelansicht liest (#7846).
 *
 * „Einen Termin ansehen" gab es in vier Ausführungen und drei
 * Interaktionsmodellen. Die Felder waren überall dieselben — diese Datei hält
 * fest, in welcher Form sie herauskommen.
 */
beforeEach(function () {
    // Das Paket kennt kein Benutzermodell — es bekommt eines genannt und weiss
    // nur, dass es `name` hat. Ohne eines steht in den Pruefungen „Class
    // App\Models\User not found", und das sagt etwas ueber die Pruefung, nicht
    // ueber das Paket.
    config()->set('calendar.user_model', DemoUser::class);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }
});

function detailTermin(array $werte = []): CalendarEvent
{
    return CalendarEvent::create(array_merge([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Jahresgespräch',
        'starts_at' => '2026-09-08 09:00',
        'ends_at' => '2026-09-08 10:30',
        'location' => 'Büro Hamburg',
    ], $werte));
}

it('nennt Tag und Uhrzeiten getrennt', function () {
    $daten = app(EventDetailPresenter::class)->present(detailTermin());

    expect($daten['date'])->toBe('2026-09-08')
        ->and($daten['start'])->toBe('09:00')
        ->and($daten['end'])->toBe('10:30')
        ->and($daten['endDate'])->toBeNull();
});

/**
 * Ein `endDate`, das immer dem Beginn gleicht, lässt die Ansicht jeden Termin
 * als Zeitraum schreiben — deshalb steht es nur bei mehrtägigen.
 */
it('setzt endDate nur bei mehrtaegigen', function () {
    $daten = app(EventDetailPresenter::class)->present(
        detailTermin(['ends_at' => '2026-09-10 10:30'])
    );

    expect($daten['endDate'])->toBe('2026-09-10');
});

it('laesst bei ganztaegig die Uhrzeiten weg', function () {
    $daten = app(EventDetailPresenter::class)->present(
        detailTermin(['all_day' => true, 'starts_at' => '2026-09-08 00:00', 'ends_at' => '2026-09-08 23:59'])
    );

    expect($daten['start'])->toBeNull()
        ->and($daten['end'])->toBeNull()
        ->and($daten['allDay'])->toBeTrue();
});

/** Dieselbe Entscheidung wie beim `AgendaAssembler`: Die Tafel liegt im Produkt. */
it('faerbt nicht selbst, nennt aber die Art', function () {
    $daten = app(EventDetailPresenter::class)->present(detailTermin());

    expect($daten['colour'])->toBeNull()
        ->and($daten['kind'])->toBe('business')
        ->and($daten['kindLabel'])->not->toBeEmpty();
});

describe('die Teilnehmer', function () {
    /**
     * Der Name des KONTOS gewinnt über die Spalte: Wer umbenannt wurde, soll
     * nicht unter dem Namen weiterstehen, den die Einladung damals trug.
     */
    it('nimmt den Namen des Kontos vor der Spalte', function () {
        $person = DemoUser::create(['name' => 'Anna Neu']);

        $termin = detailTermin();
        $termin->attendees()->create([
            'user_id' => $person->id,
            'name' => 'Anna Alt',
            'status' => 'accepted',
        ]);

        $daten = app(EventDetailPresenter::class)->present($termin);

        expect($daten['attendees'])->toBe([['name' => 'Anna Neu', 'status' => 'accepted']]);
    });

    it('nimmt die Spalte, wenn es kein Konto gibt', function () {
        $termin = detailTermin();
        $termin->attendees()->create(['user_id' => null, 'name' => 'Anna Extern', 'status' => 'accepted']);

        expect(app(EventDetailPresenter::class)->present($termin)['attendees'])
            ->toBe([['name' => 'Anna Extern', 'status' => 'accepted']]);
    });

    /** Externe Gäste haben kein Konto — dann bleibt die Adresse. */
    it('faellt auf die Mailadresse zurueck', function () {
        $termin = detailTermin();
        $termin->attendees()->create(['user_id' => null, 'email' => 'gast@example.com']);

        expect(app(EventDetailPresenter::class)->present($termin)['attendees'])
            ->toBe([['name' => 'gast@example.com', 'status' => null]]);
    });

    /**
     * `pending` ist der Vorgabewert der Spalte und sagt nichts — „eingeladen
     * und noch nicht geantwortet" ist der Normalfall. Angezeigt wird nur eine
     * echte Antwort.
     */
    it('zeigt pending nicht als Antwort', function () {
        $termin = detailTermin();
        $termin->attendees()->create(['user_id' => null, 'name' => 'Bert', 'status' => 'pending']);

        expect(app(EventDetailPresenter::class)->present($termin)['attendees'][0]['status'])->toBeNull();
    });

    /** Niemand heisst „—", und eine leere Marke sieht nach einem Fehler aus. */
    it('laesst einen namenlosen Teilnehmer weg', function () {
        $termin = detailTermin();
        $termin->attendees()->create(['user_id' => null]);

        expect(app(EventDetailPresenter::class)->present($termin)['attendees'])->toBe([]);
    });
});

it('meldet einen privaten Termin als privat', function () {
    $daten = app(EventDetailPresenter::class)->present(detailTermin(['visibility' => 'private']));

    expect($daten['private'])->toBeTrue();
});

it('zaehlt auch „confidential" als privat — dieselbe Spalte wie im ICS-Export', function () {
    $daten = app(EventDetailPresenter::class)->present(detailTermin(['visibility' => 'confidential']));

    expect($daten['private'])->toBeTrue();
});

it('meldet einen gewoehnlichen Termin NICHT als privat', function () {
    expect(app(EventDetailPresenter::class)->present(detailTermin())['private'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Serienhinweis (#8307)
|--------------------------------------------------------------------------
|
| `recurring` las bis zum 19.10.2026 `$event->rrule` — eine Eigenschaft, die es
| am Modell gar nicht gibt. Der Wert war also IMMER `false`, in allen vier
| Produkten, seit es das Feld gibt. Kein Test hat es gemerkt, weil `false` eine
| plausible Antwort ist: Die meisten Termine sind keine Serie.
|
| Gefunden beim Umstellen der Manager-Einzelansicht, und zwar nur deshalb, weil
| dort ueberhaupt jemand auf den Hinweis geschaut hat.
*/

it('meldet einen Serientermin als Serie', function () {
    $daten = app(EventDetailPresenter::class)->present(detailTermin([
        'recurrence_rules' => ['frequency' => 'weekly', 'byDay' => ['TU']],
    ]));

    expect($daten['recurring'])->toBeTrue();
});

it('meldet einen Einzeltermin NICHT als Serie', function () {
    expect(app(EventDetailPresenter::class)->present(detailTermin())['recurring'])->toBeFalse();
});
