<?php

namespace Peppermint\Calendar\Ics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Models\CalendarEvent;
use Spatie\IcalendarGenerator\Components\Calendar as IcsCalendar;
use Spatie\IcalendarGenerator\Components\Event as IcsEvent;
use Spatie\IcalendarGenerator\Enums\Classification;
use Spatie\IcalendarGenerator\Enums\EventStatus;
use Spatie\IcalendarGenerator\Enums\ParticipationStatus;
use Spatie\IcalendarGenerator\Properties\TextProperty;
use Symfony\Component\HttpFoundation\Response;

/**
 * Termine als iCalendar.
 *
 * Das Zusammensetzen erledigt spatie/icalendar-generator. RFC 5545 ist
 * Kleinarbeit mit vielen Kanten — Faltung in Oktett, Maskierungsreihenfolge,
 * das exklusive DTEND ganztaegiger Termine, Zeitzonenkomponenten, PARTSTAT.
 * Das pflegt jemand anders besser als wir nebenbei.
 *
 * Was hier bleibt, ist die Uebersetzung: unser Terminmodell in seine Objekte.
 * Die schreibt man in jedem Fall selbst.
 */
class IcsExporter
{
    /**
     * @param  iterable<CalendarEvent>  $events
     */
    public function calendar(iterable $events, ?string $name = null, string $method = 'PUBLISH'): string
    {
        $calendar = IcsCalendar::create($name ?? '')
            ->productIdentifier((string) config('calendar.ics.prodid'))
            // Alle Zeiten gehen als UTC hinaus. Die Bibliothek legt dafuer
            // sonst eine VTIMEZONE-Komponente fuer UTC an — korrekt, aber
            // sinnlos: Ein Z am Zeitstempel sagt dasselbe in einer Zeile.
            ->withoutAutoTimezoneComponents()
            // METHOD kennt die Bibliothek nicht als eigene Angabe. Roh
            // angehaengt, weil PUBLISH und REQUEST feste Woerter sind und
            // nichts zu maskieren haben.
            ->appendProperty(TextProperty::create('METHOD', $method)->withoutEscaping());

        foreach ($events as $event) {
            $calendar->event($this->component($event));
        }

        return $calendar->get();
    }

    /** Ein einzelner Termin als vollstaendige Datei — fuer Mail-Anhaenge. */
    public function event(CalendarEvent $event, string $method = 'PUBLISH'): string
    {
        return $this->calendar([$event], null, $method);
    }

    /**
     * Unser Termin als Baustein der Bibliothek.
     *
     * Oeffentlich, damit eine Anwendung ihn in einen eigenen Kalender haengen
     * kann — etwa neben Eintraege, die keine Termine sind.
     */
    public function component(CalendarEvent $event): IcsEvent
    {
        $start = CarbonImmutable::parse($event->field('starts_at'));
        $end = CarbonImmutable::parse($event->field('ends_at'));
        $allDay = (bool) $event->field('all_day');

        $component = IcsEvent::create()
            ->uniqueIdentifier($this->uid($event))
            ->name((string) $event->title)
            ->startsAt($allDay ? $start->startOfDay() : $start->utc())
            // Das exklusive DTEND ganztaegiger Termine macht die Bibliothek
            // NICHT — geprueft, sie schreibt denselben Tag zweimal. Ein
            // eintaegiger Termin verschwindet damit in manchen Clients und
            // dauert in anderen null Minuten.
            ->endsAt($allDay ? $end->startOfDay()->addDay() : $end->utc());

        if ($allDay) {
            $component->fullDay();
        }

        if (filled($event->description)) {
            $component->description(strip_tags((string) $event->description));
        }

        if (filled($event->location)) {
            $component->address((string) $event->location);
        }

        if ($event->field('visibility') === 'confidential') {
            $component->classification(Classification::Private);
        }

        if ($event->trashed()) {
            $component->status(EventStatus::Cancelled);
        }

        $this->addCategories($component, $event);
        $this->addRecurrence($component, $event);
        $this->addAttendees($component, $event);

        return $component;
    }

    /**
     * Kategorien kennt die Bibliothek nicht — und der naive Weg ueber ihre
     * Text-Angabe waere falsch: Sie maskiert das Komma zu `\,`, bei CATEGORIES
     * ist das Komma aber das TRENNZEICHEN. Aus zwei Kategorien wuerde eine.
     *
     * Also unmaskiert anhaengen und jeden Wert selbst maskieren.
     */
    protected function addCategories(IcsEvent $component, CalendarEvent $event): void
    {
        if (! app(CategoryRegistry::class)->enabled()) {
            return;
        }

        $property = IcsCategories::property($event->kindDefinition()->categories($event));

        if ($property !== null) {
            $component->appendProperty($property);
        }
    }

    /**
     * Ohne die Regel kaeme eine Serie als einzelner Termin an, und der Leser
     * erfuehre nie, dass sie sich wiederholt.
     */
    protected function addRecurrence(IcsEvent $component, CalendarEvent $event): void
    {
        IcsRecurrence::apply(
            $component,
            $event->recurrence_rules,
            CarbonImmutable::parse($event->field('starts_at')),
            $event->recurrence_until,
            $event->recurrence_exceptions,
            (bool) $event->field('all_day'),
        );
    }

    protected function addAttendees(IcsEvent $component, CalendarEvent $event): void
    {
        foreach ($event->attendees as $attendee) {
            if (! filled($attendee->email)) {
                continue;
            }

            $component->attendee(
                (string) $attendee->email,
                $attendee->name,
                $this->partStat($attendee->status),
            );
        }
    }

    protected function uid(CalendarEvent $event): string
    {
        return $event->field('uid').'@'.config('calendar.ics.uid_domain');
    }

    protected function partStat(?string $status): ParticipationStatus
    {
        return match ($status) {
            'accepted' => ParticipationStatus::Accepted,
            'declined' => ParticipationStatus::Declined,
            'tentative' => ParticipationStatus::Tentative,
            default => ParticipationStatus::NeedsAction,
        };
    }

    /**
     * @param  Collection<int, CalendarEvent>  $events
     */
    public function download(Collection $events, string $filename, ?string $name = null): Response
    {
        return response($this->calendar($events, $name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
