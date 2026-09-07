<?php

namespace Peppermint\Calendar\Enums;

enum Frequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    /** Frequencies that repeat on named weekdays and therefore require `byDay`. */
    public function needsWeekdays(): bool
    {
        return $this === self::Weekly || $this === self::Biweekly;
    }
}
