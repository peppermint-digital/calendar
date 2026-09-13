<?php

namespace Peppermint\Calendar\Ics;

use Spatie\IcalendarGenerator\Enums\RecurrenceFrequency;
use Spatie\IcalendarGenerator\ValueObjects\RRule;

/**
 * Behelf, bis spatie/icalendar-generator das `Z` an UNTIL setzt.
 *
 * RFC 5545 §3.3.10: Ist DTSTART eine UTC-Zeit, MUSS auch UNTIL eine sein.
 * Bei DTSTART traegt die Bibliothek die Zone im Parameter (`TZID=`), und das
 * genuegt — innerhalb einer RRULE gibt es aber keine Parameter, dort muss das
 * `Z` am Wert selbst stehen. `DateTimeValue::format()` schreibt es nie.
 *
 * Folge ohne diesen Behelf: eine schwebende Endzeit in einer UTC-Serie. Die
 * meisten Clients raten dann richtig, manche nicht — und geraten wird nur,
 * weil die Angabe fehlt.
 *
 * Faellt weg, sobald das oben behoben ist.
 */
class UtcRRule extends RRule
{
    /**
     * Eigene Fabrik, weil die geerbte `new self` zurueckgibt statt
     * `new static` — ueber sie kaeme immer die Elternklasse heraus, und die
     * Korrektur unten liefe ins Leere.
     */
    public static function of(RecurrenceFrequency $frequency): self
    {
        return new self($frequency);
    }

    /** @return array<string, mixed> */
    public function compose(): array
    {
        $parts = parent::compose();

        if (isset($parts['UNTIL']) && is_string($parts['UNTIL']) && ! str_ends_with($parts['UNTIL'], 'Z')) {
            $parts['UNTIL'] .= 'Z';
        }

        return $parts;
    }
}
