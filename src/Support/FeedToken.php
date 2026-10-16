<?php

namespace Peppermint\Calendar\Support;

use Illuminate\Support\Str;

/**
 * Der Token, mit dem ein Kalender abonniert wird.
 *
 * ## Warum das nicht jedes Produkt selbst macht
 *
 * Der Token ist das **einzige**, was zwischen einem Fremden und diesem
 * Kalender steht — es gibt keine Anmeldung dahinter. Vier Systeme, die ihn
 * jeweils selbst erzeugen, sind vier Gelegenheiten, ihn zu kurz, zu
 * vorhersagbar oder aus der falschen Quelle zu nehmen. Und zwar unauffällig:
 * Ein schwacher Token funktioniert genauso gut wie ein starker.
 *
 * ## Warum `Str::random()` und nicht `uniqid()` oder `md5(time())`
 *
 * `Str::random()` zieht aus `random_bytes()`, also aus der
 * Zufallsquelle des Betriebssystems. `uniqid()` ist die Uhrzeit in Hex —
 * wer weiss, wann ein Konto angelegt wurde, hat den Suchraum auf Sekunden
 * eingegrenzt. `md5(time())` ist dasselbe mit einem Hut.
 *
 * ## Warum 64 Zeichen
 *
 * Das ist, was die vier Systeme in ihrer Spalte haben (`string('…', 64)`), und
 * es passt zur Untergrenze des Feeds (32). Länger bringt nichts, kürzer
 * verschenkt den Spielraum zwischen Vorgabe und Riegel: Fällt jemandem später
 * ein, 40 zu nehmen, soll das noch durchgehen.
 */
final class FeedToken
{
    public const LENGTH = 64;

    /** Ein frischer Token aus der Zufallsquelle des Systems. */
    public static function make(): string
    {
        return Str::random(self::LENGTH);
    }

    /**
     * Taugt dieser Token überhaupt etwas?
     *
     * Dieselbe Grenze wie im `IcsFeedController` — hier, damit ein Produkt sie
     * beim Anlegen prüfen kann, statt zu merken, dass der Feed 404 gibt.
     */
    public static function acceptable(?string $token): bool
    {
        return $token !== null && strlen(trim($token)) >= 32;
    }
}
