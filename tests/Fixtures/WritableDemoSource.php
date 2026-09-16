<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Peppermint\Calendar\Exceptions\ExternalSourceFailed;
use Peppermint\Calendar\Sources\ExternalEvent;
use Peppermint\Calendar\Sources\ExternalKind;
use Peppermint\Calendar\Sources\NewExternalEvent;
use Peppermint\Calendar\Sources\WritableEventSource;

/** Steht fuer ein anderes System, in dem man auch anlegen darf. */
class WritableDemoSource extends WritableEventSource
{
    public static bool $writable = true;

    /** @var array<int, NewExternalEvent> */
    public static array $received = [];

    /** Was verschoben und geloescht wurde — und ob es scheitern soll. */
    public static array $verschoben = [];

    public static array $geloescht = [];

    public static bool $scheitert = false;

    public function key(): string
    {
        return 'writable-demo';
    }

    public function label(): string
    {
        return 'Writable demo system';
    }

    public function isWritable(): bool
    {
        return self::$writable;
    }

    public function kinds(): array
    {
        return [
            new ExternalKind('business', 'Business', ['location']),
            new ExternalKind('meeting_digital', 'Digital meeting', ['meeting_url']),
        ];
    }

    public function events(int $userId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [];
    }

    public function create(int $userId, NewExternalEvent $event): ExternalEvent
    {
        self::$received[] = $event;

        // Das Zielsystem macht seine eigene Fassung daraus — hier: es rundet
        // auf die volle Stunde und haengt seine Kennung an.
        return new ExternalEvent(
            sourceKey: $this->key(),
            id: '99',
            title: $event->title,
            startsAt: $event->startsAt->startOfHour(),
            endsAt: $event->endsAt->startOfHour(),
            allDay: $event->allDay,
            location: $event->location,
            extra: $event->extra,
        );
    }

    public function move(
        int $userId,
        string $eventId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?bool $allDay = null,
    ): ExternalEvent {
        if (self::$scheitert) {
            throw ExternalSourceFailed::beim('verschieben', $this->key());
        }

        self::$verschoben[] = compact('eventId', 'startsAt', 'endsAt', 'allDay');

        // Auch beim Verschieben rundet das Zielsystem — und genau seine
        // Fassung muss angezeigt werden, nicht die gezogene.
        return new ExternalEvent(
            sourceKey: $this->key(),
            id: $eventId,
            title: 'Kundentermin',
            startsAt: $startsAt->startOfHour(),
            endsAt: $endsAt->startOfHour(),
            allDay: $allDay ?? false,
        );
    }

    public function delete(int $userId, string $eventId): void
    {
        if (self::$scheitert) {
            throw ExternalSourceFailed::beim('loeschen', $this->key());
        }

        self::$geloescht[] = $eventId;
    }
}
