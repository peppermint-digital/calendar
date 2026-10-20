<?php

namespace Peppermint\Calendar\Http;

use Illuminate\Support\Facades\Route;
use Peppermint\Calendar\Http\Controllers\CategoryAdminController;
use Peppermint\Calendar\Http\Controllers\EventCategoryAssignmentController;
use Peppermint\Calendar\Http\Controllers\EventSourceController;
use Peppermint\Calendar\Http\Controllers\IcsFeedController;

/**
 * Die Routen, die das Paket mitbringt — zum Legen, nicht zum Erben.
 *
 * ## Warum sie nicht von selbst erscheinen
 *
 * Ein Paket, das eine Route selbst registriert, entscheidet damit auch ueber
 * ihre Absicherung: Praefix, Anmeldeschranke, Drosselung. Beim Kalender waeren
 * das Schreibwege in FREMDE Systeme — die letzte Stelle, an der ein Produkt
 * die Kontrolle abgeben sollte.
 *
 * Also ruft das Produkt sie dort auf, wo seine eigenen Schranken schon stehen:
 *
 * ```php
 * Route::middleware(['web', 'auth'])->prefix('api/calendar')->group(function () {
 *     CalendarRoutes::sources();
 * });
 * ```
 *
 * Damit bleibt im Produkt, was laut Vertrag dort bleibt — Autorisierung und
 * Adressen —, und es verschwindet, was ueberall gleich aussah: die vier
 * Vorgaenge dahinter.
 *
 * ## Die Namen sind Teil des Vertrags
 *
 * `calendar.sources.*` — sie stehen in `route()`-Aufrufen der Oberflaechen.
 * Ein Produkt, das sie umbenennt, bricht seine eigenen Masken; eines, das sie
 * uebernimmt, kann dieselbe Maske benutzen wie die anderen.
 */
class CalendarRoutes
{
    /**
     * Die vier Vorgaenge am Kalender eines anderen Systems.
     *
     * Der Platzhalter `{source}` ist der Quellenschluessel („manager"), nicht
     * eine Nummer. `{event}` ist die Kennung DRUEBEN und darf deshalb alles
     * tragen, was ein fremdes System als Kennung benutzt — eine Einschraenkung
     * auf Ziffern haette CalDAV-Kennungen ausgeschlossen, die auf `.ics` enden.
     */
    /**
     * Die Seiten des Kalenders — Pfade und Namen vom Paket, Verhalten vom Produkt.
     *
     * ## Warum das Paket die Adressen vorgibt
     *
     * Am 16.10.2026 hiess „Termin anlegen" in vier Systemen vier verschiedene
     * Dinge: `/calendar/create`, `/calendar/neu` und zweimal
     * `/kalender/termine/neu`. Dieselbe Maske, vier Adressen — und damit vier
     * Satz Lesezeichen, vier Satz Verweise und kein gemeinsamer Link, den man
     * irgendwo hinschreiben koennte (#7846).
     *
     * Englisch, weil der Code es ist. Die deutschen Pfade der beiden aelteren
     * Systeme bleiben als dauerhafte Weiterleitung bestehen; eine Adresse, die
     * einmal funktioniert hat, ist billig zu erhalten und teuer zu verlieren.
     *
     * ## Was hier NICHT steht
     *
     * Der Controller. Jedes Produkt bringt seinen eigenen mit — er kennt die
     * Datenbank, die Rechte und die Felder dieses Systems. Geteilt sind
     * Adressen und Namen, nicht das Verhalten dahinter.
     *
     * ```php
     * Route::middleware(['web', 'auth'])->group(function () {
     *     CalendarRoutes::pages(CalendarController::class);
     * });
     * ```
     *
     * ## Die Reihenfolge ist nicht beliebig
     *
     * `create` und `agenda` stehen VOR `{event}`. Andersherum verschluckt der
     * Platzhalter sie: `/calendar/create` waere dann ein Termin mit der
     * Kennung „create", und die Seite zeigte einen 404 statt der Maske.
     *
     * @param  class-string  $controller  Der Controller des Produkts.
     * @param  array<int, string>  $only   Nur diese Vorgaenge legen. Leer = alle.
     */
    public static function pages(string $controller, array $only = []): void
    {
        $nimmt = static fn (string $name): bool => $only === [] || in_array($name, $only, true);

        if ($nimmt('index')) {
            Route::get('calendar', [$controller, 'index'])->name('calendar.index');
        }

        // Vor `{event}` — sonst verschluckt der Platzhalter sie.
        if ($nimmt('create')) {
            Route::get('calendar/create', [$controller, 'create'])->name('calendar.create');
        }

        if ($nimmt('agenda')) {
            Route::get('calendar/agenda', [$controller, 'agenda'])->name('calendar.agenda');
        }

        // Ebenfalls VOR `{event}`, aus demselben Grund.
        //
        // Diese Zeile stand bis zum 20.10.2026 in DREI Produkten von Hand —
        // im Manager, im CRM und in der Verwaltung, jeweils mit einem eigenen
        // Kommentar ueber die Reihenfolge. Genau das ist Frage 3 des
        // Paketvertrags („Schreibt jedes Produkt denselben Verdrahtungscode?"),
        // und die Antwort war dreimal ja. Dass sie hier fehlte, ist auch der
        // Grund, warum das AI Brain als einziges System gar keine
        // Terminfindung hatte: Es gab nichts, was es nur haette aufrufen
        // muessen (#8307).
        if ($nimmt('findTime')) {
            Route::get('calendar/find-time', [$controller, 'findTime'])
                ->name('calendar.find-time');
        }

        if ($nimmt('store')) {
            Route::post('calendar', [$controller, 'store'])->name('calendar.store');
        }

        if ($nimmt('show')) {
            Route::get('calendar/{event}', [$controller, 'show'])->name('calendar.show');
        }

        if ($nimmt('edit')) {
            Route::get('calendar/{event}/edit', [$controller, 'edit'])->name('calendar.edit');
        }

        if ($nimmt('update')) {
            Route::put('calendar/{event}', [$controller, 'update'])->name('calendar.update');
        }

        if ($nimmt('destroy')) {
            Route::delete('calendar/{event}', [$controller, 'destroy'])->name('calendar.destroy');
        }
    }

    /**
     * Die deutschen Pfade der aelteren Systeme auf die englischen umlenken.
     *
     * Dauerhaft (301), nicht vorlaeufig: Diese Adressen standen in Lesezeichen,
     * in Mails und in Verweisen anderer Seiten. Wer sie aufruft, soll ankommen
     * und nicht auf einer 404 landen.
     */
    public static function germanRedirects(): void
    {
        Route::permanentRedirect('kalender', 'calendar');
        Route::permanentRedirect('kalender/termine/neu', 'calendar/create');
    }

    /**
     * Der ICS-Feed — die EINZIGE nicht angemeldete Route dieses Pakets.
     *
     * Bewusst ein eigener Aufruf und nicht Teil von `pages()`: Wer einen
     * oeffentlichen Endpunkt aufmacht, soll das in seiner Routendatei SEHEN.
     * Eine Zeile, die beim Ueberfliegen wie die anderen aussieht, waere genau
     * die, die niemand hinterfragt.
     *
     * Sie gehoert NICHT in eine `auth`-Gruppe — ein Kalenderprogramm hat keine
     * Sitzung. Der Token ist die Berechtigung.
     *
     * ```php
     * // ausserhalb jeder auth-Gruppe:
     * CalendarRoutes::feed();
     * ```
     */
    public static function feed(string $pfad = 'calendar/feed/{token}.ics'): void
    {
        Route::get($pfad, IcsFeedController::class)->name('calendar.feed');
    }

    /**
     * Die Pflege der Kategorienliste.
     *
     * Vom Produkt dort aufgerufen, wo seine Einstellungen schon stehen — im
     * AI Brain in dessen Einstellungsmenue, in einem Produkt ohne Brain in
     * seinem eigenen:
     *
     * ```php
     * Route::middleware(['web', 'auth'])->prefix('api/calendar')->group(function () {
     *     CalendarRoutes::categories();
     * });
     * ```
     *
     * Die Namen `calendar.categories.*` gehoeren zum Vertrag: Die Maske aus
     * dem Paket ruft sie auf. Ein Produkt, das sie umbenennt, kann die Maske
     * nicht mehr benutzen.
     */
    public static function categories(): void
    {
        Route::get('categories', [CategoryAdminController::class, 'index'])
            ->name('calendar.categories.index');
        Route::post('categories', [CategoryAdminController::class, 'store'])
            ->name('calendar.categories.store');
        Route::patch('categories/{category}', [CategoryAdminController::class, 'update'])
            ->whereNumber('category')
            ->name('calendar.categories.update');
        Route::delete('categories/{category}', [CategoryAdminController::class, 'destroy'])
            ->whereNumber('category')
            ->name('calendar.categories.destroy');
    }

    /**
     * Die Kategorien eines einzelnen Termins setzen.
     *
     * Getrennt von {@see pages()}, weil es kein Seitenaufruf ist, sondern ein
     * kleiner Schreibweg aus der Detailansicht heraus — und weil ein Produkt
     * ihn weglassen kann, ohne auf die Seiten zu verzichten.
     *
     * ```php
     * Route::middleware(['web', 'auth'])->group(function () {
     *     CalendarRoutes::eventCategories();
     * });
     * ```
     *
     * Der Name `calendar.event.categories.update` gehoert zum Vertrag: Die
     * Detailansicht aus dem Paket ruft ihn auf.
     */
    public static function eventCategories(): void
    {
        Route::patch('calendar/{event}/categories', [EventCategoryAssignmentController::class, 'update'])
            ->name('calendar.event.categories.update');
    }

    public static function sources(): void
    {
        Route::get('sources/{source}/kinds', [EventSourceController::class, 'kinds'])
            ->name('calendar.sources.kinds');

        Route::post('sources/{source}/events', [EventSourceController::class, 'store'])
            ->name('calendar.sources.store');

        Route::patch('sources/{source}/events/{event}', [EventSourceController::class, 'move'])
            ->where('event', '.*')
            ->name('calendar.sources.move');

        Route::delete('sources/{source}/events/{event}', [EventSourceController::class, 'destroy'])
            ->where('event', '.*')
            ->name('calendar.sources.destroy');
    }
}
