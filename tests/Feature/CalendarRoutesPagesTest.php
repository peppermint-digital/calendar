<?php

use Illuminate\Support\Facades\Route;
use Peppermint\Calendar\Http\CalendarRoutes;

/**
 * Die Seitenrouten des Pakets.
 *
 * Am 16.10.2026 hiess „Termin anlegen" in vier Systemen vier verschiedene
 * Dinge. Diese Tests halten die eine Fassung fest — Pfade und Namen, nicht das
 * Verhalten dahinter (#7846).
 */
beforeEach(function () {
    Route::middleware('web')->group(fn () => CalendarRoutes::pages('App\\Fake\\CalendarController'));
    Route::getRoutes()->refreshNameLookups();
});

function routePath(string $name): ?string
{
    $route = Route::getRoutes()->getByName($name);

    return $route?->uri();
}

it('legt alle Seiten unter einem englischen Pfad', function () {
    expect(routePath('calendar.index'))->toBe('calendar');
    expect(routePath('calendar.create'))->toBe('calendar/create');
    expect(routePath('calendar.agenda'))->toBe('calendar/agenda');
    expect(routePath('calendar.show'))->toBe('calendar/{event}');
    expect(routePath('calendar.edit'))->toBe('calendar/{event}/edit');
});

/**
 * Andersherum verschluckt der Platzhalter sie: `/calendar/create` waere dann
 * ein Termin mit der Kennung „create", und die Seite zeigte einen 404 statt
 * der Maske.
 */
it('legt create und agenda VOR den Platzhalter', function () {
    $pfade = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'calendar') && in_array('GET', $r->methods(), true))
        ->map(fn ($r) => $r->uri())
        ->values();

    $create = $pfade->search('calendar/create');
    $platzhalter = $pfade->search('calendar/{event}');

    expect($create)->not->toBeFalse();
    expect($platzhalter)->not->toBeFalse();
    expect($create)->toBeLessThan($platzhalter);
});

/**
 * Das Brain loescht ueber einen Dateipfad statt ueber eine Zeilennummer; seine
 * `destroy` passt nicht ins Schema. Ein Produkt muss deshalb waehlen duerfen,
 * welche Vorgaenge es uebernimmt — sonst legt das Paket ihm eine Route, die
 * sein Controller nicht bedienen kann.
 */
it('legt nur, was das Produkt auch bedient', function () {
    Route::setRoutes(new Illuminate\Routing\RouteCollection);
    Route::middleware('web')->group(fn () => CalendarRoutes::pages('App\\Fake\\CalendarController', ['index', 'create']));
    Route::getRoutes()->refreshNameLookups();

    expect(routePath('calendar.index'))->toBe('calendar');
    expect(routePath('calendar.create'))->toBe('calendar/create');
    expect(routePath('calendar.show'))->toBeNull();
    expect(routePath('calendar.destroy'))->toBeNull();
});

/**
 * Geprueft wird die Route, nicht der Aufruf: Ein echter Aufruf braeuchte einen
 * `APP_KEY`, den die Paketumgebung nicht hat — und er wuerde Laravels
 * Weiterleitung pruefen, nicht unsere Zuordnung.
 */
it('lenkt die alten deutschen Pfade dauerhaft um', function () {
    Route::setRoutes(new Illuminate\Routing\RouteCollection);
    Route::middleware('web')->group(fn () => CalendarRoutes::germanRedirects());

    $ziele = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'kalender'))
        ->mapWithKeys(fn ($r) => [$r->uri() => $r->defaults]);

    expect($ziele)->toHaveCount(2);
    expect($ziele['kalender']['destination'])->toBe('calendar');
    expect($ziele['kalender']['status'])->toBe(301);
    expect($ziele['kalender/termine/neu']['destination'])->toBe('calendar/create');
    expect($ziele['kalender/termine/neu']['status'])->toBe(301);
});

/**
 * Der Bericht liegt unter derselben Adresse wie in den anderen Systemen.
 *
 * Der Manager fuehrt ihn seit laengerem unter `calendar/category-report`; das
 * Brain bekam ihn gestern, CRM und Verwaltung heute. Vier Systeme, EINE
 * Adresse — das war der Grund, warum die Pfade ueberhaupt aus dem Paket
 * kommen: Am 16.10.2026 hiess „Termin anlegen" in vier Systemen vier
 * verschiedene Dinge.
 */
it('legt die Auswertung unter die gemeinsame Adresse', function () {
    Route::middleware('web')->group(fn () => CalendarRoutes::pages('App\\Fake\\CalendarController', ['categoryReport']));

    $route = Route::getRoutes()->getByName('calendar.category-report');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('calendar/category-report');
});

/**
 * VOR `{event}`: Sonst liest der Router „category-report" als Kennung und das
 * Produkt antwortet mit „Termin nicht gefunden" statt mit dem Bericht.
 */
it('legt die Auswertung vor den Platzhalter', function () {
    Route::middleware('web')->group(fn () => CalendarRoutes::pages('App\\Fake\\CalendarController', ['categoryReport', 'show']));

    $namen = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->getName())
        ->filter()
        ->values();

    expect($namen->search('calendar.category-report'))
        ->toBeLessThan($namen->search('calendar.show'));
});

/**
 * Die Terminfindung gehoert ins Paket, nicht in drei Routendateien.
 *
 * Bis zum 20.10.2026 legte jedes Produkt diese Route selbst — Manager, CRM und
 * Verwaltung mit demselben Pfad, demselben Namen und jeweils einem eigenen
 * Kommentar ueber die Reihenfolge. Das AI Brain hatte sie deshalb gar nicht:
 * Es gab nichts, was es nur haette aufrufen muessen (#8307).
 */
it('legt die Terminfindung mit demselben Pfad und Namen wie die drei Produkte', function () {
    expect(routePath('calendar.find-time'))->toBe('calendar/find-time');
});

/**
 * „find-time" waere sonst eine Terminkennung, und die Seite zeigte einen 404 —
 * dieselbe Falle wie bei `create` und `category-report`.
 */
it('legt die Terminfindung VOR den Platzhalter', function () {
    $pfade = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'calendar') && in_array('GET', $r->methods(), true))
        ->map(fn ($r) => $r->uri())
        ->values();

    $findTime = $pfade->search('calendar/find-time');
    $platzhalter = $pfade->search('calendar/{event}');

    expect($findTime)->not->toBeFalse();
    expect($platzhalter)->not->toBeFalse();
    expect($findTime)->toBeLessThan($platzhalter);
});

/**
 * Ein Produkt ohne Terminfindung soll die Route auch nicht bekommen — sonst
 * zeigt sie auf eine Methode, die es nicht hat.
 */
it('laesst die Terminfindung weg, wenn das Produkt sie nicht nennt', function () {
    Route::setRoutes(new Illuminate\Routing\RouteCollection);
    Route::middleware('web')->group(fn () => CalendarRoutes::pages('App\\Fake\\CalendarController', ['index']));
    Route::getRoutes()->refreshNameLookups();

    expect(routePath('calendar.find-time'))->toBeNull();
});
