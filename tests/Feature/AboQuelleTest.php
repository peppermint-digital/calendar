<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Brain\KalenderFreigabe;
use Peppermint\Calendar\Sources\BrainSubscribedEventSource;

/*
| Die abonnierten Kalender aus der Nabe, als ANZEIGE in einem Produkt
| (Bug #1081).
|
| Seit dem 29.10.2026 liegen die Abos in AI Brain. Ohne diese Quelle
| verschwinden sie damit aus den Produkt-Kalendern — im Manager waeren das 1009
| kommende Termine, und das waere der sichtbarste Rueckschritt des ganzen
| Umbaus.
|
| Geprueft wird die Quelle und nicht die Nabe: Der Aufruf ist ersetzt.
*/

/** Eine Quelle, deren Nabe antwortet, was der Test vorgibt. */
function aboQuelle(array $antwort, ?int $person = 7, bool $freigeschaltet = true): BrainSubscribedEventSource
{
    $freigabe = new class($person, $freigeschaltet) extends KalenderFreigabe
    {
        public function __construct(private readonly ?int $person, private readonly bool $frei)
        {
            parent::__construct();
        }

        public function fuerAngemeldetePerson(): bool
        {
            return $this->frei;
        }

        public function personId(): int|string|null
        {
            return $this->person;
        }
    };

    return new class($freigabe, $antwort) extends BrainSubscribedEventSource
    {
        /** @var array<int, array<string, mixed>> */
        public array $aufrufe = [];

        public function __construct(KalenderFreigabe $freigabe, private readonly array $antwort)
        {
            parent::__construct($freigabe);
        }

        protected function brueckeVorhanden(): bool
        {
            return true;
        }

        protected function ruf(string $werkzeug, array $argumente = []): array
        {
            $this->aufrufe[] = ['werkzeug' => $werkzeug, 'argumente' => $argumente];

            if (isset($this->antwort['__fehler'])) {
                throw new RuntimeException((string) $this->antwort['__fehler']);
            }

            return $this->antwort;
        }
    };
}

function aboFenster(): array
{
    return [CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08')];
}

it('bringt die Abo-Termine der Nabe in die Form des Kalenders', function () {
    $quelle = aboQuelle([
        'timezone' => 'Europe/Berlin',
        'events' => [[
            'id' => 42,
            'title' => 'Kundentermin ueber Teams',
            'starts_at' => '2026-11-05T11:00:00+01:00',
            'ends_at' => '2026-11-05T12:00:00+01:00',
            'all_day' => false,
            'location' => 'Teams',
            'subscription' => 'Outlook',
        ]],
    ]);

    [$von, $bis] = aboFenster();
    $termine = $quelle->events(7, $von, $bis);

    expect($termine)->toHaveCount(1);
    expect($termine[0]->title)->toBe('Kundentermin ueber Teams');
    expect($termine[0]->startsAt->setTimezone('Europe/Berlin')->format('H:i'))->toBe('11:00');
    expect($termine[0]->location)->toBe('Teams');
    // Das Abo steht als `extra` dabei, damit die Oberflaeche „Outlook" neben
    // den Termin schreiben kann, statt alle Abos in einen Topf zu werfen.
    expect($termine[0]->toArray()['extra']['subscription'] ?? null)->toBe('Outlook');
    // Die Kennung traegt das Quellen-Vorzeichen — sie darf nie mit einer
    // lokalen Termin-Kennung verwechselt werden.
    expect($termine[0]->toArray()['id'])->toBe('ai-brain-abos:42');
});

it('laesst `extra` ganz weg, wenn die Nabe kein Abo nennt', function () {
    // „Ein Schluessel, der immer da und meist leer ist, lehrt den Leser, ihn zu
    // uebersehen" — der Kommentar steht an `ExternalEvent::toArray()`, und er
    // gilt auch fuer den, der ihn fuellt.
    $quelle = aboQuelle(['events' => [['id' => 1, 'title' => 'Ohne Abo-Namen', 'starts_at' => '2026-11-05T09:00:00+01:00']]]);
    [$von, $bis] = aboFenster();

    expect($quelle->events(7, $von, $bis)[0]->toArray())->not->toHaveKey('extra');
});

it('fragt die Nabe mit dem Fenster, nicht mit der Person', function () {
    // Die Nabe liefert ausschliesslich die Termine der HANDELNDEN Person, und
    // handelnd ist, wer angemeldet ist. Eine Personenangabe im Aufruf waere
    // eine Behauptung ueber Identitaet, die hier niemand aufstellen darf.
    $quelle = aboQuelle(['events' => []]);
    [$von, $bis] = aboFenster();

    $quelle->events(7, $von, $bis);

    expect($quelle->aufrufe[0]['werkzeug'])->toBe('list-subscribed-events-tool');
    expect($quelle->aufrufe[0]['argumente'])->toBe(['from' => '2026-11-02', 'to' => '2026-11-08']);
});

it('gibt fuer eine FREMDE Person nichts heraus — und fragt nicht einmal', function () {
    // In einem abonnierten Privatkalender steht, was jemand privat vorhat.
    // Dieselbe Regel wie bei den privaten Terminen (Manager Bug #657): Auch
    // ein Administrator beim Kalender-Umschalten bekommt hier nichts.
    $quelle = aboQuelle(['events' => [['id' => 1, 'title' => 'Geheim', 'starts_at' => '2026-11-05T09:00:00+01:00']]], person: 7);
    [$von, $bis] = aboFenster();

    expect($quelle->events(99, $von, $bis))->toBe([]);
    expect($quelle->aufrufe)->toBe([]);
});

it('bietet sich nicht an, wenn die Person ihren Kalender nicht freigeschaltet hat', function () {
    expect(aboQuelle(['events' => []], freigeschaltet: false)->isAvailable())->toBeFalse();
});

it('bietet sich nicht an, wenn das Produkt sie abschaltet', function () {
    config()->set('calendar.brain_subscriptions.enabled', false);

    expect(aboQuelle(['events' => []])->isAvailable())->toBeFalse();
});

it('wirft bei einer nicht erreichbaren Nabe, statt still leer zu liefern', function () {
    // Still leer hiesse „keine Abo-Termine", und das Register koennte den
    // Unterschied zu einem Ausfall nicht sehen. Mit der Ausnahme laesst es die
    // Quelle weg, loggt sie — und der uebrige Kalender bleibt sichtbar.
    $quelle = aboQuelle(['__fehler' => 'Zeitgrenze']);
    [$von, $bis] = aboFenster();

    expect(fn () => $quelle->events(7, $von, $bis))
        ->toThrow(RuntimeException::class, 'AI Brain nicht erreichbar');
});

it('ueberspringt eine Zeile ohne Beginn, statt den ganzen Abruf zu verlieren', function () {
    $quelle = aboQuelle(['events' => [
        ['id' => 1, 'title' => 'Ohne Beginn'],
        ['id' => 2, 'title' => 'Mit Beginn', 'starts_at' => '2026-11-05T09:00:00+01:00'],
    ]]);
    [$von, $bis] = aboFenster();

    $termine = $quelle->events(7, $von, $bis);

    expect($termine)->toHaveCount(1);
    expect($termine[0]->title)->toBe('Mit Beginn');
});

it('gibt einem Ganztagstermin ohne Ende den ganzen Tag', function () {
    $quelle = aboQuelle(['timezone' => 'Europe/Berlin', 'events' => [
        ['id' => 1, 'title' => 'Feiertag', 'starts_at' => '2026-11-05T00:00:00+01:00', 'all_day' => true],
    ]]);
    [$von, $bis] = aboFenster();

    $termin = $quelle->events(7, $von, $bis)[0];

    expect($termin->allDay)->toBeTrue();
    expect($termin->endsAt->setTimezone('Europe/Berlin')->format('H:i'))->toBe('23:59');
});

it('ist NICHT schreibbar', function () {
    // Ein Abo-Termin wird drueben geaendert. Eine schreibbare Quelle wuerde
    // eine Aenderung anbieten, die der naechste Abgleich stillschweigend
    // zuruecknimmt — schlimmer als keine Aenderung.
    expect(aboQuelle(['events' => []]))->not->toBeInstanceOf(\Peppermint\Calendar\Sources\WritableEventSource::class);
});
