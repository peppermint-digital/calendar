<?php

namespace Peppermint\Calendar\Ics;

/**
 * Was von der eigenen iCalendar-Erzeugung uebrig ist.
 *
 * Das Zusammensetzen macht seit dem Umstieg spatie/icalendar-generator. Diese
 * beiden Stuecke bleiben, weil sie etwas koennen, was die Bibliothek nicht
 * anbietet: auf ein **fertiges, fremdes** Dokument angewendet zu werden.
 *
 * Anwendungen, die ihre Zeilen historisch selbst zusammenbauen, kommen damit
 * zu korrekter Faltung, ohne alles auf einmal umstellen zu muessen. Der
 * Peppermint Manager tut das an sieben Stellen.
 *
 * Diese Klasse ist ein Uebergang. Sind die letzten Handstellen auf die
 * Bibliothek umgestellt, faellt sie weg.
 */
class IcsWriter
{
    /** RFC 5545: eine Inhaltszeile ist hoechstens 75 Oktett lang. */
    protected const OCTET_LIMIT = 75;

    /**
     * Maskiert Backslash, Zeilenumbrueche, Semikolon und Komma.
     *
     * Backslash zuerst — sonst maskiert der Durchgang die Zeichen mit, die er
     * selbst gerade eingefuegt hat, und aus einem Zeilenumbruch wird ein
     * sichtbares Backslash-n im fremden Kalender.
     */
    public function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);

        return str_replace([';', ','], ['\;', '\\,'], $value);
    }

    /**
     * Faltet ein fertiges iCalendar-Dokument nachtraeglich.
     *
     * Maskieren vergisst kaum jemand — es faellt beim ersten Semikolon auf.
     * Falten vergessen fast alle, weil bis zur ersten laengeren Beschreibung
     * nichts passiert. Und dann passiert es im Kalender des Empfaengers.
     *
     * Bereits gefaltete Fortsetzungszeilen bleiben unangetastet, sonst zerlegt
     * ein zweiter Durchlauf die Faltung des ersten.
     */
    public static function foldDocument(string $document): string
    {
        $writer = new self;

        $lines = array_map(
            static fn (string $line): string => rtrim($line, "\r"),
            explode("\n", $document),
        );

        $out = array_map(
            static fn (string $line): string => str_starts_with($line, ' ') || str_starts_with($line, "\t")
                ? $line
                : $writer->fold($line),
            $lines,
        );

        return implode("\r\n", $out);
    }

    /**
     * Gefaltet wird in Oktett, nicht in Zeichen: Ein Umlaut belegt zwei, und
     * mitten hindurch getrennt entsteht Zeichensalat — bei deutschen Titeln
     * kein Randfall.
     */
    protected function fold(string $line): string
    {
        if (strlen($line) <= self::OCTET_LIMIT) {
            return $line;
        }

        $folded = '';
        $current = '';

        foreach (mb_str_split($line) as $character) {
            // Die Fortsetzungszeile beginnt mit einem Leerzeichen, das zaehlt
            // mit — nach dem ersten Stueck bleiben also 74 Oktett.
            $limit = $folded === '' ? self::OCTET_LIMIT : self::OCTET_LIMIT - 1;

            if (strlen($current) + strlen($character) > $limit) {
                $folded .= ($folded === '' ? '' : "\r\n ").$current;
                $current = '';
            }

            $current .= $character;
        }

        return $folded.($folded === '' ? '' : "\r\n ").$current;
    }
}
