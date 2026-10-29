<?php

namespace Peppermint\Calendar\Tests\Support;

/**
 * Was der naechste Feed-Aufruf in einer Pruefung bekommt.
 *
 * ## Warum eine veraenderliche Stelle und nicht ein zweites `Http::fake()`
 *
 * Laravel haengt weitere `Http::fake()`-Aufrufe an die Liste der Attrappen AN,
 * und bei gleichem Muster gewinnt die ERSTE. Ein Test, der den Feed zweimal
 * verschieden antworten lassen will — erst mit zwei Terminen, dann mit einem —
 * bekommt also zweimal dieselbe Antwort.
 *
 * Das Schadensbild ist tueckisch, weil es nach einem Fehler im PRUEFLING
 * aussieht: Der Abgleich „nimmt nichts weg", „erkennt keine Aenderung",
 * „meldet keinen Fehlschlag". Drei rote Tests, eine Ursache, und die lag in
 * der Attrappe.
 *
 * Deshalb EINE Attrappe, die hierher liest, und die Tests setzen hier.
 */
class FeedAttrappe
{
    public static string $inhalt = '';

    public static int $status = 200;

    public static function zuruecksetzen(): void
    {
        self::$inhalt = '';
        self::$status = 200;
    }
}
