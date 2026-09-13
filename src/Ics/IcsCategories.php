<?php

namespace Peppermint\Calendar\Ics;

use Spatie\IcalendarGenerator\Properties\TextProperty;

/**
 * Die eine Zeile, die spatie/icalendar-generator nicht richtig hinbekommt.
 *
 * Bei `CATEGORIES` ist das Komma nach RFC 5545 das Trennzeichen zwischen den
 * Etiketten. Die Bibliothek maskiert es wie in jedem anderen Text — aus zwei
 * Kategorien wird dann eine mit einem Komma im Namen.
 *
 * Also unmaskiert anhaengen und jeden Wert selbst maskieren. Das sind vier
 * Zeilen, und genau deshalb steht es hier statt dreimal in den Anwendungen:
 * Vier Zeilen schreibt man gern noch einmal, und beim dritten Mal ist eine
 * davon anders.
 */
final class IcsCategories
{
    /**
     * `CATEGORIES` mit dem Komma als Trennzeichen, oder null, wenn nichts
     * uebrig bleibt — eine leere Zeile ist schlechter als keine.
     *
     * @param  array<int, string>  $labels
     */
    public static function property(array $labels): ?TextProperty
    {
        $clean = array_values(array_filter(
            array_map(static fn (string $label): string => trim($label), $labels),
            static fn (string $label): bool => $label !== '',
        ));

        if ($clean === []) {
            return null;
        }

        return TextProperty::create(
            'CATEGORIES',
            implode(',', array_map(self::escape(...), $clean)),
        )->withoutEscaping();
    }

    /**
     * Maskiert Backslash, Zeilenumbrueche, Semikolon und Komma.
     *
     * Backslash zuerst — sonst maskiert der Durchgang die Zeichen mit, die er
     * selbst gerade eingefuegt hat, und aus einem Zeilenumbruch wird ein
     * sichtbares Backslash-n im fremden Kalender.
     */
    private static function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);

        return str_replace([';', ','], ['\;', '\\,'], $value);
    }
}
