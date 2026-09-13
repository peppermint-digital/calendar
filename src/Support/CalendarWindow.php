<?php

namespace Peppermint\Calendar\Support;

use Carbon\CarbonImmutable;

/**
 * Ein Zeitfenster, wie eine Oberflaeche oder ein Werkzeug es meint.
 *
 * Klein, aber geteilt, weil beide Seiten es sonst unterschiedlich falsch
 * machen: Ein Fenster ist in der Zone der Anwendung gemeint, nicht in UTC, und
 * es endet am Ende eines Tages, nicht am Anfang des naechsten.
 */
final class CalendarWindow
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /**
     * Loest `today`, `week`, `month` oder ein ausdrueckliches Datumspaar auf.
     *
     * Ausdrueckliche Daten gewinnen: Wer beides schickt, meint beides — und ein
     * Bereichswort daneben ist bestenfalls Gewohnheit des Aufrufers.
     *
     * Ein unbekanntes Wort faellt auf `week` zurueck statt zu werfen. Am
     * anderen Ende sitzt oft ein Sprachmodell, und eine Woche auf einen Tippfehler
     * ist eine bessere Antwort als eine Ausnahme.
     */
    public static function resolve(
        ?string $range = null,
        ?string $from = null,
        ?string $to = null,
        ?string $timezone = null,
    ): self {
        $timezone ??= config('app.timezone') ?: 'UTC';

        if ($from !== null && $from !== '' && $to !== null && $to !== '') {
            return new self(
                CarbonImmutable::parse($from, $timezone)->startOfDay(),
                CarbonImmutable::parse($to, $timezone)->endOfDay(),
            );
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();

        return match ($range) {
            'today' => new self($today, $today->endOfDay()),

            // Zwei Fallen in einer Zeile.
            //
            // Kalenderrechnung statt Sekunden: Der 25.10. hat in Europe/Berlin
            // 25 Stunden und der 29.03. deren 23. Wer Tage in Sekunden
            // weiterzaehlt, verliert zweimal im Jahr einen Tag — und merkt es
            // nie, weil Bauserver in UTC laufen.
            //
            // Und `NoOverflow`, weil der 31. kein Februar-Datum ist: Mit
            // ueberlaufendem addMonth wird aus dem 31.01. der 03.03., und das
            // Fenster ist drei Tage zu lang.
            'month' => new self($today, $today->addMonthNoOverflow()->endOfDay()),

            default => new self($today, $today->addDays(6)->endOfDay()),
        };
    }

    /** Woche heisst hier: heute und die sechs folgenden Tage. */
    public function days(): int
    {
        return $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }
}
