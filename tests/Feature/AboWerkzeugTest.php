<?php

use Illuminate\Auth\GenericUser;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Peppermint\Calendar\Mcp\ListSubscribedEventsTool;
use Peppermint\Calendar\Subscriptions\Models\CalendarSubscription;

/*
| Das Werkzeug, mit dem ein Produkt die Abo-Termine aus der Nabe holt
| (Bug #1081).
|
| Das Gegenstueck zu `BrainSubscribedEventSource`. Geprueft wird hier vor allem
| das, was kein Produkt pruefen kann: dass es NUR die Abos der handelnden
| Person herausgibt — in einem abonnierten Privatkalender steht, was jemand
| privat vorhat.
*/

beforeEach(function () {
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    // `GenericUser` und kein Eloquent-Modell: Das Paket kennt kein
    // Benutzermodell, und das Werkzeug soll mit einer Wache zurechtkommen,
    // deren Nutzer keines ist. Genau daran war eine fruehere Fassung zu eng.
    $this->ich = new GenericUser(['id' => 7]);
    $this->andere = new GenericUser(['id' => 99]);
});

function werkzeugAbo(GenericUser $person, array $felder = []): CalendarSubscription
{
    return CalendarSubscription::create(array_merge([
        'user_id' => $person->getAuthIdentifier(),
        'name' => 'Outlook',
        'url' => 'https://outlook.example.test/feed.ics',
        'is_active' => true,
    ], $felder));
}

function werkzeugTermin(CalendarSubscription $abo, array $felder = []): object
{
    return $abo->events()->create(array_merge([
        'uid' => 'u-'.uniqid(),
        'title' => 'Kundentermin',
        'start_datetime' => now()->addDay()->setTime(11, 0),
        'end_datetime' => now()->addDay()->setTime(12, 0),
        'all_day' => false,
    ], $felder));
}

function aboWerkzeug(array $argumente = []): array
{
    $antwort = app(ListSubscribedEventsTool::class)->handle(new Request($argumente));

    $daten = json_decode((string) $antwort->content(), true);

    return is_array($daten) ? $daten : [];
}

it('gibt ohne handelnde Person eine LEERE Liste heraus, nicht alles', function () {
    // Der Rueckfall „keine Identitaet, also alles zeigen" war im Manager
    // Bug #657. Hier waere er schlimmer: Es sind Privatkalender.
    werkzeugTermin(werkzeugAbo($this->ich));

    expect(aboWerkzeug()['events'])->toBe([]);
});

it('gibt die Abo-Termine der handelnden Person heraus', function () {
    $this->actingAs($this->ich);
    werkzeugTermin(werkzeugAbo($this->ich), ['title' => 'Meiner']);

    $antwort = aboWerkzeug(['range' => 'month']);

    expect($antwort['events'])->toHaveCount(1);
    expect($antwort['events'][0]['title'])->toBe('Meiner');
    expect($antwort['events'][0]['subscription'])->toBe('Outlook');
    // MIT Zone und nicht als Wanduhrzeit: Die Gegenseite hat eine eigene
    // `app.timezone`, und an dieser Naht ist schon zweimal etwas um zwei
    // Stunden verrutscht.
    expect($antwort['events'][0]['starts_at'])->toContain('+01:00');
    expect($antwort['timezone'])->toBe('Europe/Berlin');
});

it('gibt die Abos einer ANDEREN Person nicht heraus', function () {
    $this->actingAs($this->ich);
    werkzeugTermin(werkzeugAbo($this->andere), ['title' => 'Fremder']);

    expect(aboWerkzeug(['range' => 'month'])['events'])->toBe([]);
});

it('laesst ein pausiertes Abo heraus', function () {
    // Wer sein Abo pausiert, will die Termine nicht mehr sehen — auch nicht in
    // einem anderen System.
    $this->actingAs($this->ich);
    werkzeugTermin(werkzeugAbo($this->ich, ['is_active' => false]));

    expect(aboWerkzeug(['range' => 'month'])['events'])->toBe([]);
});

it('haelt sich an das Fenster', function () {
    $this->actingAs($this->ich);
    $abo = werkzeugAbo($this->ich);
    werkzeugTermin($abo, ['title' => 'Drin', 'start_datetime' => now()->addDay()]);
    werkzeugTermin($abo, ['title' => 'Draussen', 'start_datetime' => now()->addMonths(6)]);

    $titel = array_column(aboWerkzeug([
        'from' => now()->toDateString(),
        'to' => now()->addDays(7)->toDateString(),
    ])['events'], 'title');

    expect($titel)->toBe(['Drin']);
});

it('gibt die Termine in zeitlicher Reihenfolge heraus', function () {
    // Damit die Gegenseite nicht sortieren muss — und vor allem, damit zwei
    // Produkte nicht verschieden sortieren.
    $this->actingAs($this->ich);
    $abo = werkzeugAbo($this->ich);
    werkzeugTermin($abo, ['title' => 'Spaeter', 'start_datetime' => now()->addDays(3)]);
    werkzeugTermin($abo, ['title' => 'Frueher', 'start_datetime' => now()->addDay()]);

    expect(array_column(aboWerkzeug(['range' => 'month'])['events'], 'title'))->toBe(['Frueher', 'Spaeter']);
});
