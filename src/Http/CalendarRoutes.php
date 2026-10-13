<?php

namespace Peppermint\Calendar\Http;

use Illuminate\Support\Facades\Route;
use Peppermint\Calendar\Http\Controllers\EventSourceController;

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
