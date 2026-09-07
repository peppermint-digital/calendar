<?php

namespace Peppermint\Calendar\Recurrence;

use Peppermint\Calendar\Enums\Frequency;
use Peppermint\Calendar\Enums\MonthDayOverflow;
use Peppermint\Calendar\Exceptions\InvalidRecurrenceRule;

/**
 * A validated recurrence rule.
 *
 * Rules are stored as plain arrays on the event; this object is what you get
 * once they have been checked. Building one is the validation — there is no
 * way to hold an instance that describes an impossible series.
 */
class RecurrenceRule
{
    public const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /**
     * @param  array<int, string>  $byDay
     */
    public function __construct(
        public readonly Frequency $frequency,
        public readonly array $byDay = [],
        public readonly ?int $byMonthDay = null,
        public readonly MonthDayOverflow $monthDayOverflow = MonthDayOverflow::Skip,
    ) {}

    /**
     * @param  array<string, mixed>  $rules
     *
     * @throws InvalidRecurrenceRule
     */
    public static function fromArray(array $rules): self
    {
        $frequency = Frequency::tryFrom((string) ($rules['frequency'] ?? ''))
            ?? throw InvalidRecurrenceRule::frequency($rules['frequency'] ?? null);

        $byDay = $rules['byDay'] ?? [];

        if ($frequency->needsWeekdays()) {
            if (! is_array($byDay) || $byDay === []) {
                throw InvalidRecurrenceRule::missingWeekdays();
            }

            foreach ($byDay as $day) {
                if (! in_array($day, self::WEEKDAYS, true)) {
                    throw InvalidRecurrenceRule::weekday((string) $day);
                }
            }
        }

        $byMonthDay = null;

        if ($frequency === Frequency::Monthly && isset($rules['byMonthDay'])) {
            $byMonthDay = (int) $rules['byMonthDay'];

            if ($byMonthDay < 1 || $byMonthDay > 31) {
                throw InvalidRecurrenceRule::monthDay($rules['byMonthDay']);
            }
        }

        return new self(
            frequency: $frequency,
            byDay: is_array($byDay) ? array_values($byDay) : [],
            byMonthDay: $byMonthDay,
            monthDayOverflow: MonthDayOverflow::tryFrom((string) ($rules['monthDayOverflow'] ?? ''))
                ?? MonthDayOverflow::Skip,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'frequency' => $this->frequency->value,
            'byDay' => $this->byDay,
            'byMonthDay' => $this->byMonthDay,
            'monthDayOverflow' => $this->monthDayOverflow->value,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
