<?php

namespace Peppermint\Calendar\Ics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Peppermint\Calendar\Enums\Frequency;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Recurrence\RecurrenceRule;

class IcsExporter
{
    /**
     * @param  iterable<CalendarEvent>  $events
     */
    public function calendar(iterable $events, ?string $name = null, string $method = 'PUBLISH'): string
    {
        $writer = new IcsWriter;

        $writer->begin('VCALENDAR')
            ->property('VERSION', '2.0', raw: true)
            ->property('PRODID', config('calendar.ics.prodid'), raw: true)
            ->property('CALSCALE', 'GREGORIAN', raw: true)
            ->property('METHOD', $method, raw: true)
            ->property('X-WR-CALNAME', $name);

        foreach ($events as $event) {
            $this->event($event, $writer);
        }

        return $writer->end('VCALENDAR')->toString();
    }

    public function event(CalendarEvent $event, ?IcsWriter $writer = null): string
    {
        $writer ??= new IcsWriter;

        $start = CarbonImmutable::parse($event->field('starts_at'));
        $end = CarbonImmutable::parse($event->field('ends_at'));

        $writer->begin('VEVENT')
            ->property('UID', $this->uid($event), raw: true)
            ->property('DTSTAMP', $this->utc(CarbonImmutable::now()), raw: true);

        if ($event->field('all_day')) {
            // DTEND is exclusive for all-day events: a one-day event ends on
            // the following day. Emitting the same date makes it disappear in
            // some clients and last zero minutes in others.
            $writer->property('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE'], raw: true)
                ->property('DTEND', $end->addDay()->format('Ymd'), ['VALUE' => 'DATE'], raw: true);
        } else {
            $writer->property('DTSTART', $this->utc($start), raw: true)
                ->property('DTEND', $this->utc($end), raw: true);
        }

        $writer->property('SUMMARY', $event->title)
            ->property('DESCRIPTION', $event->description === null ? null : strip_tags($event->description))
            ->property('LOCATION', $event->location)
            ->property('URL', $event->meeting_url);

        if ($rrule = $this->rrule($event)) {
            $writer->property('RRULE', $rrule, raw: true);
        }

        // A confidential event still travels — its details do not. Clients that
        // honour CLASS hide title and description from other viewers.
        if ($event->field('visibility') === 'confidential') {
            $writer->property('CLASS', 'PRIVATE', raw: true);
        }

        foreach ($event->attendees as $attendee) {
            $address = $attendee->email;

            if ($address === null || $address === '') {
                continue;
            }

            $writer->property('ATTENDEE', 'mailto:'.$address, array_filter([
                'CN' => $attendee->name === null ? null : $this->quote($attendee->name),
                'PARTSTAT' => $this->partStat($attendee->status),
            ]), raw: true);
        }

        if ($event->trashed()) {
            $writer->property('STATUS', 'CANCELLED', raw: true);
        }

        $writer->property('LAST-MODIFIED', $this->utc(CarbonImmutable::parse($event->updated_at)), raw: true);

        return $writer->end('VEVENT')->toString();
    }

    /**
     * Translates the package's recurrence rule into an RRULE. Without this an
     * exported series arrives as a single appointment and the reader never
     * learns that it repeats.
     */
    public function rrule(CalendarEvent $event): ?string
    {
        $rules = $event->recurrence_rules;

        if (! is_array($rules) || $rules === []) {
            return null;
        }

        $rule = RecurrenceRule::fromArray($rules);

        $parts = match ($rule->frequency) {
            Frequency::Daily => ['FREQ=DAILY'],
            Frequency::Weekly => ['FREQ=WEEKLY'],
            Frequency::Biweekly => ['FREQ=WEEKLY', 'INTERVAL=2'],
            Frequency::Monthly => ['FREQ=MONTHLY'],
        };

        if ($rule->frequency->needsWeekdays() && $rule->byDay !== []) {
            $parts[] = 'BYDAY='.implode(',', $rule->byDay);
        }

        if ($rule->frequency === Frequency::Monthly && $rule->byMonthDay !== null) {
            $parts[] = 'BYMONTHDAY='.$rule->byMonthDay;
        }

        if ($event->recurrence_until !== null) {
            $parts[] = 'UNTIL='.CarbonImmutable::parse($event->recurrence_until)->endOfDay()->utc()->format('Ymd\THis\Z');
        }

        return implode(';', $parts);
    }

    protected function uid(CalendarEvent $event): string
    {
        return $event->field('uid').'@'.config('calendar.ics.uid_domain');
    }

    protected function utc(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Ymd\THis\Z');
    }

    protected function quote(string $value): string
    {
        return '"'.str_replace('"', '', $value).'"';
    }

    protected function partStat(?string $status): string
    {
        return match ($status) {
            'accepted' => 'ACCEPTED',
            'declined' => 'DECLINED',
            'tentative' => 'TENTATIVE',
            default => 'NEEDS-ACTION',
        };
    }

    /**
     * @param  Collection<int, CalendarEvent>  $events
     */
    public function download(Collection $events, string $filename, ?string $name = null): \Symfony\Component\HttpFoundation\Response
    {
        return response($this->calendar($events, $name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
