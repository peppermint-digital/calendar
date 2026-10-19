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
            Frequency::Yearly => $this->everyYear($first, $last, $rule),
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

    /**
     * Jahr fuer Jahr, am selben Tag im selben Monat.
     *
     * Wie {@see everyMonth()} in Schritten der Einheit und nicht Tag fuer Tag:
     * Ein Geburtstag ueber zehn Jahre sind zehn Schritte, nicht 3652.
     *
     * ## Der 29. Februar
     *
     * Ein am 29.02. geborener Mensch hat in drei von vier Jahren kein
     * Geburtsdatum im Kalender. Geregelt wird das von derselben Einstellung
     * wie der 31. in einem kurzen Monat:
     *
     *   - `Skip`  — nur in Schaltjahren. Formal richtig, und in einem
     *               Geburtstagskalender die falsche Antwort: Der Mensch hat
     *               auch 2027 Geburtstag.
     *   - `Clamp` — der 28. Februar. Ein Tag, den niemand gewaehlt hat, aber
     *               der Hinweis kommt im richtigen Jahr.
     *
     * Die Vorgabe des Pakets bleibt, was sie bei `monthly` ist — die
     * Anwendung entscheidet, weil nur sie weiss, wofuer ihre Serien stehen.
     * Eine Terminart „Geburtstag" setzt `Clamp`.
     *
     * @return array<int, CarbonImmutable>
     */
    protected function everyYear(CarbonImmutable $first, CarbonImmutable $last, RecurrenceRule $rule): array
    {
        $out = [];

        for ($jahr = $first; $jahr->lessThanOrEqualTo($last); $jahr = $jahr->addYear()) {
            // `addYear()` auf dem 29.02. landet in Carbon selbst schon auf dem
            // 28.02. des Folgejahres — und wuerde den 29. danach fuer immer
            // verlieren. Deshalb wird jedes Vorkommen aus Monat und Tag des
            // ERSTEN Termins neu gebildet, nicht aus dem vorigen fortgezaehlt.
            $monat = $jahr->setMonth($first->month)->startOfMonth();
            $tageImMonat = $monat->daysInMonth;

            if ($first->day > $tageImMonat && $rule->monthDayOverflow === MonthDayOverflow::Skip) {
                continue;
            }

            $tag = $monat->setDay(min($first->day, $tageImMonat))
                ->setTime($first->hour, $first->minute, $first->second);

            if ($tag->lessThan($first) || $tag->greaterThan($last)) {
                continue;
            }

            $out[] = $tag;

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
