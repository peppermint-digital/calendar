<?php

namespace Peppermint\Calendar\Exceptions;

use InvalidArgumentException;

class InvalidRecurrenceRule extends InvalidArgumentException
{
    public static function frequency(mixed $given): self
    {
        return new self(trans('calendar::calendar.recurrence.invalid_frequency', [
            'given' => is_scalar($given) ? (string) $given : gettype($given),
        ]));
    }

    public static function missingWeekdays(): self
    {
        return new self(trans('calendar::calendar.recurrence.missing_weekdays'));
    }

    public static function weekday(string $given): self
    {
        return new self(trans('calendar::calendar.recurrence.invalid_weekday', ['given' => $given]));
    }

    public static function monthDay(mixed $given): self
    {
        return new self(trans('calendar::calendar.recurrence.invalid_month_day', [
            'given' => is_scalar($given) ? (string) $given : gettype($given),
        ]));
    }
}
