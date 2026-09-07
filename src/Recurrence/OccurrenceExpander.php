<?php

namespace Peppermint\Calendar\Recurrence;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Peppermint\Calendar\Exceptions\InvalidRecurrenceRule;
use Peppermint\Calendar\Models\CalendarEvent;

class OccurrenceExpander
{
    public function __construct(
        protected RecurrenceCalculator $calculator,
    ) {}

    /**
     * Turns stored events into the appearances that fall inside a window.
     *
     * Without this a weekly appointment shows up once — the rule is saved and
     * never read. Events without a rule pass through unchanged.
     *
     * The window follows the same convention as `scopeInRange`: `$to` is a
     * moment, not a day, and it is exclusive. Callers that mean "including all
     * of that day" pass its end — mixing both readings in one package is how an
     * appointment ends up missing from exactly one view.
     *
     * @param  iterable<CalendarEvent>  $events
     * @return Collection<int, Occurrence>
     */
    public function expand(iterable $events, DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        $windowStart = CarbonImmutable::instance($from);
        $windowEnd = CarbonImmutable::instance($to);
        $out = collect();

        foreach ($events as $event) {
            $starts = CarbonImmutable::parse($event->field('starts_at'));
            $ends = CarbonImmutable::parse($event->field('ends_at'));
            $length = $starts->diffInSeconds($ends);

            $rules = $event->recurrence_rules;

            if (! is_array($rules) || $rules === []) {
                $out->push(new Occurrence($event, $starts, $ends));

                continue;
            }

            try {
                $rule = RecurrenceRule::fromArray($rules);
            } catch (InvalidRecurrenceRule) {
                // Eine unbrauchbare Regel darf den Termin nicht verschwinden
                // lassen: Der Eintrag ist echt, nur seine Wiederholung nicht
                // lesbar. Er erscheint einmal, an seinem eigenen Datum.
                $out->push(new Occurrence($event, $starts, $ends));

                continue;
            }

            // Nur bis zum Ende der Serie rechnen — sonst erzeugt eine unbefristete
            // Regel im Jahr 2040 immer noch Termine.
            $until = $event->recurrence_until !== null
                ? CarbonImmutable::parse($event->recurrence_until)->endOfDay()->min($windowEnd)
                : $windowEnd;

            // Abgesagte oder verschobene Vorkommen. Sie stehen als Datum am
            // Termin — die Regel bleibt unangetastet, denn wer einen Dienstag
            // absagt, meint nicht „ab jetzt keine Dienstage mehr".
            $exceptions = array_map(
                fn ($date) => CarbonImmutable::parse($date)->toDateString(),
                is_array($event->recurrence_exceptions) ? $event->recurrence_exceptions : [],
            );

            foreach ($this->calculator->occurrences($starts, $rule, $until) as $date) {
                $occurrenceEnd = $date->addSeconds($length);

                if ($date->greaterThan($windowEnd) || $occurrenceEnd->lessThan($windowStart)) {
                    continue;
                }

                if (in_array($date->toDateString(), $exceptions, true)) {
                    continue;
                }

                $out->push(new Occurrence(
                    event: $event,
                    startsAt: $date,
                    endsAt: $occurrenceEnd,
                    isFirst: $date->equalTo($starts),
                ));
            }
        }

        return $out->sortBy(fn (Occurrence $occurrence) => $occurrence->startsAt->getTimestamp())->values();
    }
}
