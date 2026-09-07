<?php

namespace Peppermint\Calendar\Recurrence;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Peppermint\Calendar\Enums\Frequency;
use Peppermint\Calendar\Enums\MonthDayOverflow;

class RecurrenceCalculator
{
    /**
     * Upper bound on returned occurrences. A series is a convenience, not a
     * bulk import — a rule that would produce more than this is almost always
     * a mistake in the rule, and expanding it would be the expensive way to
     * find that out.
     */
    public const MAX_OCCURRENCES = 730;

    /**
     * Start datetimes of every occurrence between $start and $until, inclusive.
     *
     * Each occurrence keeps the time of day of $start; only the date moves.
     *
     * @return array<int, CarbonImmutable>
     */
    public function occurrences(CarbonInterface $start, RecurrenceRule $rule, CarbonInterface $until): array
    {
        $first = CarbonImmutable::parse($start);
        $last = CarbonImmutable::parse($until)->endOfDay();

        if ($last->lessThan($first)) {
            return [];
        }

        return match ($rule->frequency) {
            Frequency::Daily => $this->everyDay($first, $last),
            Frequency::Weekly => $this->onWeekdays($first, $last, $rule, everyOtherWeek: false),
            Frequency::Biweekly => $this->onWeekdays($first, $last, $rule, everyOtherWeek: true),
            Frequency::Monthly => $this->everyMonth($first, $last, $rule),
        };
    }

    /** @return array<int, CarbonImmutable> */
    protected function everyDay(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $out = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $out[] = $day;

            if (count($out) >= self::MAX_OCCURRENCES) {
                break;
            }
        }

        return $out;
    }

    /** @return array<int, CarbonImmutable> */
    protected function onWeekdays(
        CarbonImmutable $first,
        CarbonImmutable $last,
        RecurrenceRule $rule,
        bool $everyOtherWeek,
    ): array {
        $wanted = $this->weekdayNumbers($rule->byDay);
        $anchorWeek = $first->startOfWeek(CarbonInterface::MONDAY);
        $out = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, $wanted, true)) {
                continue;
            }

            if ($everyOtherWeek) {
                $weeksApart = (int) $anchorWeek->diffInWeeks($day->startOfWeek(CarbonInterface::MONDAY));

                if ($weeksApart % 2 !== 0) {
                    continue;
                }
            }

            $out[] = $day;

            if (count($out) >= self::MAX_OCCURRENCES) {
                break;
            }
        }

        return $out;
    }

    /**
     * Walks month by month rather than day by day: a monthly series over two
     * years is 24 steps, not 730.
     *
     * @return array<int, CarbonImmutable>
     */
    protected function everyMonth(CarbonImmutable $first, CarbonImmutable $last, RecurrenceRule $rule): array
    {
        $wantedDay = $rule->byMonthDay ?? $first->day;
        $out = [];

        for ($month = $first->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $daysInMonth = $month->daysInMonth;

            if ($wantedDay > $daysInMonth && $rule->monthDayOverflow === MonthDayOverflow::Skip) {
                continue;
            }

            $day = $month->setDay(min($wantedDay, $daysInMonth))
                ->setTime($first->hour, $first->minute, $first->second);

            if ($day->lessThan($first) || $day->greaterThan($last)) {
                continue;
            }

            $out[] = $day;

            if (count($out) >= self::MAX_OCCURRENCES) {
                break;
            }
        }

        return $out;
    }

    /** @return array<int, int> */
    protected function weekdayNumbers(array $byDay): array
    {
        $map = [
            'MO' => CarbonInterface::MONDAY,
            'TU' => CarbonInterface::TUESDAY,
            'WE' => CarbonInterface::WEDNESDAY,
            'TH' => CarbonInterface::THURSDAY,
            'FR' => CarbonInterface::FRIDAY,
            'SA' => CarbonInterface::SATURDAY,
            'SU' => CarbonInterface::SUNDAY,
        ];

        return array_values(array_filter(
            array_map(fn ($day) => $map[$day] ?? null, $byDay),
            fn ($value) => $value !== null,
        ));
    }

    /**
     * Human-readable label for a rule, translated through the package's
     * language files so applications are not stuck with one language.
     */
    public function describe(RecurrenceRule $rule, ?CarbonInterface $until = null): string
    {
        $label = trans('calendar::calendar.frequency.'.$rule->frequency->value);

        if ($rule->frequency->needsWeekdays() && $rule->byDay !== []) {
            $days = array_map(
                fn (string $day) => trans('calendar::calendar.weekday_short.'.$day),
                $rule->byDay,
            );

            $label .= ' ('.implode(', ', $days).')';
        }

        if ($until !== null) {
            $label .= ' '.trans('calendar::calendar.recurrence.until', [
                'date' => CarbonImmutable::parse($until)->isoFormat('L'),
            ]);
        }

        return $label;
    }
}
