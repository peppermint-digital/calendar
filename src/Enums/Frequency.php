<?php

namespace Peppermint\Calendar\Enums;

enum Frequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    /**
     * Jaehrlich — fuer Geburtstage, Jahrestage, Pruefungstermine.
     *
     * Kam am 19.10.2026 dazu, und zwar aus einem konkreten Anlass: Eine
     * Terminart „Geburtstag" ohne Jahresfrequenz waere ein Termin, den man
     * jedes Jahr neu anlegt — also genau das, was eine Serie ersparen soll.
     *
     * Den 29. Februar regelt dieselbe Einstellung wie den 31. eines kurzen
     * Monats ({@see MonthDayOverflow}). Eine zweite Regel nur fuer Schaltjahre
     * waere eine zweite Antwort auf dieselbe Frage.
     */
    case Yearly = 'yearly';

    /** Frequencies that repeat on named weekdays and therefore require `byDay`. */
    public function needsWeekdays(): bool
    {
        return $this === self::Weekly || $this === self::Biweekly;
    }
}
