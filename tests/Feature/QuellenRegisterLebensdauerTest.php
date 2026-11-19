<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Peppermint\Calendar\Sources\EventSource;
use Peppermint\Calendar\Sources\EventSourceRegistry;

/*
 * Die LEBENSDAUER des Quellen-Registers (Bug #1522).
 *
 * ## Was hier festgenagelt wird
 *
 * `EventSourceRegistry` war bis zum 19.11.2026 `singleton()` gebunden, und sein
 * INHALT haengt in drei Produkten an der angemeldeten Person: Manager, CRM und
 * Verwaltung haengen per `extend()` `BrainCalendarSources::build()` hinein, und
 * das fragt beim BAUEN die Wache — ohne Person null Quellen, mit Person die
 * Systeme, fuer die DIESE Person freigeschaltet ist. Unter FPM folgenlos, der
 * Behaelter entsteht je Anfrage neu. Unter Octane und im Warteschlangen-
 * Arbeiter lebt er Stunden:
 *
 * | Reihenfolge im selben Behaelter | Folge mit `singleton()` |
 * |---|---|
 * | erst ohne Anmeldung, dann mit  | die Liste bleibt LEER |
 * | erst Person A, dann Person B   | B sieht die Liste, die fuer A gebaut wurde |
 *
 * Beides lautlos. Dieselbe Fehlerform wie #1479 im Aufgaben-Paket.
 *
 * ## Warum hier `BrainCalendarSources` NICHT selbst eingehaengt wird
 *
 * Die Bruecke ist in dieser Suite nicht installiert (Regel 7 des Paketvertrags,
 * gegengeprueft in `BrainQuellenTest`), `build()` wirft dort also, bevor es die
 * Wache erreicht. Und eine Attrappe der Fassade unter dem echten Klassennamen
 * wuerde genau den Test kippen, der behauptet, dass es sie hier NICHT gibt.
 *
 * Eingehaengt wird deshalb, was die Produkte tun, in der Form, auf die es
 * ankommt: ein `extend()`, das beim Bauen die Wache fragt und je Person
 * andere Quellen meldet (`lebensdauerEinhaengen()`). Der Fehler sitzt in der
 * BINDUNG, nicht im Einhaenger — jeder Einhaenger mit dieser Form legt ihn frei.
 *
 * ## Der Haken, an dem die Anfragegrenze haengt
 *
 * `app()->forgetScopedInstances()` ist der Griff, den Octane zwischen zwei
 * Anfragen tut und der Warteschlangen-Arbeiter zwischen zwei Aufgaben
 * (`QueueServiceProvider`, die `$resetScope`-Closure) — dieselbe Feststellung
 * wie in `RegisterLebensdauerTest` des Aufgaben-Pakets. Gerufen wird er hier
 * absichtlich, und zwar NUR er: kein `forgetInstance()`, kein neuer Behaelter.
 * Ein `singleton` uebersteht diesen Griff, ein `scoped` nicht — genau das ist
 * der Unterschied, den die Tests sehen muessen. Ohne den Griff waeren beide
 * Bindungen innerhalb eines Behaelters ununterscheidbar, und ein Test, der so
 * gebaut ist, koennte den Fehler gar nicht herstellen.
 */

/**
 * Eine Quelle, die nur einen Schluessel hat — je Person ein anderer System-Name.
 */
function lebensdauerQuelle(string $schluessel): EventSource
{
    return new class($schluessel) extends EventSource
    {
        public function __construct(private readonly string $schluessel) {}

        public function key(): string
        {
            return $this->schluessel;
        }

        public function label(): string
        {
            return ucfirst($this->schluessel);
        }

        public function events(int $userId, DateTimeInterface $from, DateTimeInterface $to): array
        {
            return [];
        }
    };
}

/**
 * Haengt ein, was ein Produkt einhaengt: beim BAUEN die Wache fragen.
 *
 * Wie `BrainCalendarSources::build()`: ohne Person nichts, mit Person die
 * Systeme, fuer die sie freigeschaltet ist — Person 1 fuer CRM und Manager,
 * Person 2 fuer die Verwaltung. Gezaehlt wird, wie oft gebaut wird; das ist
 * der Netzaufruf, den der echte Einhaenger an dieser Stelle kostet.
 */
function lebensdauerEinhaengen(?int &$gebaut): void
{
    $gebaut = 0;

    app()->extend(EventSourceRegistry::class, function (EventSourceRegistry $register) use (&$gebaut): EventSourceRegistry {
        $gebaut++;

        if (Auth::guest()) {
            return $register;
        }

        $systeme = match ((int) Auth::id()) {
            1 => ['crm', 'manager'],
            2 => ['verwaltung'],
            default => [],
        };

        foreach ($systeme as $schluessel) {
            $register->register(lebensdauerQuelle($schluessel));
        }

        return $register;
    });
}

function lebensdauerPerson(int $id): GenericUser
{
    return new GenericUser(['id' => $id, 'name' => "Person {$id}"]);
}

beforeEach(function () {
    // Die Konfiguration des Pakets traegt keine Quellen; was hier im Register
    // steht, kommt allein aus dem Einhaenger. Und es wird hier niemand
    // angemeldet: Weder `TestCase` noch `Pest.php` setzen eine Person
    // (gegengeprueft am 19.11.2026) — „kein actingAs" ist also eine Aussage.
    config()->set('calendar.sources', []);
});

/**
 * 1. Die alte Falle: erst ohne Anmeldung, dann mit.
 */
it('traegt nach einer Anfrage OHNE Anmeldung nicht die leere Liste weiter', function () {
    lebensdauerEinhaengen($gebaut);

    // --- Anfrage 1: niemand angemeldet.
    expect(app(EventSourceRegistry::class)->all())->toBe([]);

    // --- Anfrageende. Der Griff, den Octane hier tut — und nur der.
    app()->forgetScopedInstances();

    // --- Anfrage 2: jetzt mit Person.
    test()->actingAs(lebensdauerPerson(1));

    // Mit `singleton()` stuende hier [] — fuer jede weitere Anfrage dieses
    // Arbeiters, ohne Fehlermeldung.
    expect(array_keys(app(EventSourceRegistry::class)->all()))->toBe(['crm', 'manager'])
        ->and($gebaut)->toBe(2, 'Je Anfrage einmal gebaut.');
});

/**
 * 2. Die andere Richtung: erst Person A, dann Person B.
 *
 * Test 1 bestuende auch, wenn nur eine LEERE Liste neu gebaut wuerde. Erst
 * dieser sagt, dass auch eine gefuellte nicht weitergereicht wird.
 */
it('baut die Liste nach einem Personenwechsel neu, statt die der vorigen Person zu zeigen', function () {
    lebensdauerEinhaengen($gebaut);

    test()->actingAs(lebensdauerPerson(1));
    expect(array_keys(app(EventSourceRegistry::class)->all()))->toBe(['crm', 'manager']);

    app()->forgetScopedInstances();
    test()->actingAs(lebensdauerPerson(2));

    // Mit `singleton()` stuende hier ['crm', 'manager'] — die Liste von A.
    expect(array_keys(app(EventSourceRegistry::class)->all()))->toBe(['verwaltung']);
});

/**
 * 3. Der Warteschlangen-Arbeiter: dort ist „ohne Person" der Normalfall.
 *
 * Eine Aufgabe laeuft regulaer ohne Anmeldung. Handelt die naechste im Namen
 * einer Person, darf sie nicht die leere Liste der vorigen erben.
 */
it('gibt einer Aufgabe im Arbeiter nicht die Liste der vorigen Aufgabe', function () {
    lebensdauerEinhaengen($gebaut);

    // --- Aufgabe 1: ohne Person.
    app(EventSourceRegistry::class)->all();

    // --- Der Griff zwischen zwei Aufgaben (QueueServiceProvider).
    app()->forgetScopedInstances();

    // --- Aufgabe 2: im Namen einer Person.
    Auth::setUser(lebensdauerPerson(2));

    expect(array_keys(app(EventSourceRegistry::class)->all()))->toBe(['verwaltung']);
});

/**
 * 4. Die Gegenprobe zur Bindung: innerhalb einer Anfrage EIN Register.
 *
 * Ohne diesen Test machte auch `bind()` die drei oben gruen — und dann baute
 * jede Aufloesung neu, also je Aufloesung ein Netzaufruf im echten Einhaenger.
 * Eine Maske loest das Register mehrfach auf (`available()`, `writable()`,
 * `ziele()`); das waere teuer und an keiner Zahl sichtbar.
 */
it('bleibt innerhalb einer Anfrage dasselbe Register und wird nur einmal gebaut', function () {
    lebensdauerEinhaengen($gebaut);
    test()->actingAs(lebensdauerPerson(1));

    $erstes = app(EventSourceRegistry::class);

    expect(app(EventSourceRegistry::class))->toBe($erstes)
        ->and(app(EventSourceRegistry::class))->toBe($erstes)
        ->and($gebaut)->toBe(1, 'Dreimal aufgeloest, einmal gebaut.');
});
