<?php

namespace Peppermint\Calendar\Ics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Peppermint\Calendar\Enums\Frequency;
use Peppermint\Calendar\Recurrence\RecurrenceRule;
use Spatie\IcalendarGenerator\Components\Event as IcsEvent;
use Spatie\IcalendarGenerator\Enums\RecurrenceDay;
use Spatie\IcalendarGenerator\Enums\RecurrenceFrequency;

/**
 * Eine Serie als `RRULE` und `EXDATE` an einem iCalendar-Termin.
 *
 * Getrennt vom {@see IcsExporter}, weil nicht jede Anwendung ihre Termine
 * darueber baut: Der Peppermint Manager setzt sein Abo und seine CalDAV-
 * Antworten aus eigenen Bausteinen zusammen und mischt Dinge hinein, die gar
 * keine Kalendertermine sind (Fristen, Aufgaben, Feiertage). Ohne diese Klasse
 * entstuende die Regel dort ein zweites Mal von Hand — und beim zweiten Mal
 * anders.
 *
 * Ohne `RRULE` sieht ein abonnierter Kalender von einer woechentlichen Serie
 * genau einen Termin. Ohne `EXDATE` sieht er auch die abgesagten.
 */
final class IcsRecurrence
{
    /**
     * @param  array<string, mixed>|null  $rules  `recurrence_rules` des Termins
     * @param  array<int, string>|null  $exceptions  `recurrence_exceptions` — abgesagte Tage
     * @param  DateTimeInterface  $startsAt  Beginn der Serie; liefert die Uhrzeit fuer EXDATE
     */
    public static function apply(
        IcsEvent $component,
        ?array $rules,
        DateTimeInterface $startsAt,
        mixed $until = null,
        ?array $exceptions = null,
        bool $allDay = false,
    ): void {
        if (! is_array($rules) || $rules === []) {
            return;
        }

        $rule = RecurrenceRule::fromArray($rules);

        $rrule = UtcRRule::of(match ($rule->frequency) {
            Frequency::Daily => RecurrenceFrequency::Daily,
            Frequency::Weekly, Frequency::Biweekly => RecurrenceFrequency::Weekly,
            Frequency::Monthly => RecurrenceFrequency::Monthly,
        });

        // „Alle zwei Wochen" kennt iCalendar nicht als eigene Frequenz — es ist
        // eine woechentliche Regel mit Abstand zwei.
        if ($rule->frequency === Frequency::Biweekly) {
            $rrule->interval(2);
        }

        if ($rule->frequency->needsWeekdays()) {
            foreach ($rule->byDay as $day) {
                if (($tag = RecurrenceDay::tryFrom($day)) !== null) {
                    $rrule->onWeekDay($tag);
                }
            }
        }

        if ($rule->frequency === Frequency::Monthly && $rule->byMonthDay !== null) {
            $rrule->onMonthDay($rule->byMonthDay);
        }

        if ($until !== null) {
            $rrule->until(CarbonImmutable::parse($until)->endOfDay()->utc());
        }

        $component->rrule($rrule);

        self::addExceptions($component, $exceptions, $startsAt, $allDay);
    }

    /**
     * Abgesagte Vorkommen als `EXDATE` (RFC 5545 §3.8.5.1).
     *
     * `EXDATE` muss zu `DTSTART` passen — gleiche Wertart und gleiche Uhrzeit.
     * Gespeichert ist nur der Tag; die Uhrzeit kommt deshalb vom Serienbeginn.
     * Ein `EXDATE` um 00:00 an einem Termin um 09:00 trifft nichts, und das
     * faellt erst im fremden Kalender auf.
     *
     * @param  array<int, string>|null  $exceptions
     */
    private static function addExceptions(
        IcsEvent $component,
        ?array $exceptions,
        DateTimeInterface $startsAt,
        bool $allDay,
    ): void {
        if (! is_array($exceptions) || $exceptions === []) {
            return;
        }

        $start = CarbonImmutable::instance($startsAt);

        $dates = array_map(
            fn ($date) => $allDay
                ? CarbonImmutable::parse($date)->startOfDay()
                : CarbonImmutable::parse($date)
                    ->setTimeFrom($start)
                    ->setTimezone($start->getTimezone())
                    ->utc(),
            $exceptions,
        );

        $component->doNotRepeatOn(array_values($dates), ! $allDay);
    }
}
