<?php

namespace Peppermint\Calendar\Recurrence;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Änderungen an einem einzelnen Vorkommen einer Serie.
 *
 * Eine Serie ist eine Zeile mit einer Regel. Wer den Termin am nächsten
 * Dienstag absagt oder um eine Stunde verschiebt, meint damit **nicht** „ab
 * jetzt keine Dienstage mehr" — die Regel muss bleiben, wie sie ist.
 *
 * Der Weg dahin ist der der iCalendar-Welt und älter als jede Software, die
 * ihn benutzt:
 *
 *   - Das ausgenommene Datum steht als Liste am Serientermin (dort: EXDATE).
 *   - Ein verschobenes Vorkommen ist eine **eigene Zeile** ohne eigene Regel,
 *     die über `recurrence_group_id` zur Serie gehört (dort: RECURRENCE-ID).
 *
 * Beides zusammen, denn ein verschobenes Vorkommen ist an seinem alten Platz
 * abgesagt und an seinem neuen ein einzelner Termin.
 */
class SeriesEditor
{
    /**
     * Lässt ein einzelnes Vorkommen ausfallen.
     *
     * @param  DateTimeInterface  $occurrence  der Beginn des betroffenen Vorkommens
     */
    public function cancelOccurrence(CalendarEvent $series, DateTimeInterface $occurrence): CalendarEvent
    {
        $day = CarbonImmutable::instance($occurrence)->toDateString();
        $exceptions = is_array($series->recurrence_exceptions) ? $series->recurrence_exceptions : [];

        // Doppelte Absagen sind harmlos, aber sie blähen das Feld auf — und ein
        // Feld, das mit jedem Klick wächst, wird irgendwann zum Problem.
        $normalised = array_values(array_unique(array_map(
            fn ($date) => CarbonImmutable::parse($date)->toDateString(),
            [...$exceptions, $day],
        )));

        sort($normalised);

        $series->forceFill(['recurrence_exceptions' => $normalised])->save();

        return $series;
    }

    /**
     * Löst ein einzelnes Vorkommen aus der Serie heraus.
     *
     * Das Ergebnis ist ein eigenständiger Termin: Er trägt die Angaben der
     * Serie, aber keine Regel. Wer ihn danach ändert, ändert nur ihn.
     *
     * @param  array<string, mixed>  $changes  was am herausgelösten Termin anders sein soll
     */
    public function detachOccurrence(
        CalendarEvent $series,
        DateTimeInterface $occurrence,
        array $changes = [],
    ): CalendarEvent {
        $start = CarbonImmutable::instance($occurrence);
        $length = CarbonImmutable::parse($series->field('starts_at'))
            ->diffInSeconds(CarbonImmutable::parse($series->field('ends_at')));

        // Die Gruppe hält Serie und herausgelöste Vorkommen zusammen. Fehlt sie
        // noch — der Termin ist als einfache Serie entstanden —, entsteht sie
        // jetzt, sonst wäre die Verbindung nach dem ersten Herauslösen weg.
        if ($series->recurrence_group_id === null) {
            $series->forceFill([
                'recurrence_group_id' => (string) \Illuminate\Support\Str::uuid(),
                'is_recurrence_master' => true,
            ])->save();
        }

        $attributes = array_merge([
            'kind' => $series->field('kind'),
            CalendarEvent::column('owner_id') => $series->field('owner_id'),
            'title' => $series->title,
            'description' => $series->description,
            'location' => $series->location,
            CalendarEvent::column('starts_at') => $start,
            CalendarEvent::column('ends_at') => $start->addSeconds($length),
            CalendarEvent::column('all_day') => (bool) $series->field('all_day'),
            'timezone' => $series->timezone,
            'subject_type' => $series->field('subject_type'),
            'subject_id' => $series->field('subject_id'),
            'recurrence_group_id' => $series->recurrence_group_id,
            'is_recurrence_master' => false,

            // Ausdrücklich ohne Regel: Der herausgelöste Termin ist ein
            // einzelner. Erbte er die Regel der Serie, stünde er ab sofort
            // jede Woche ein zweites Mal im Kalender.
            'recurrence_rules' => null,
            'recurrence_until' => null,
            'recurrence_exceptions' => null,
        ], $changes);

        $detached = CalendarEvent::create($attributes);

        $this->cancelOccurrence($series, $start);

        return $detached;
    }
}
