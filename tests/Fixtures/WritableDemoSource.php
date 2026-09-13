<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DateTimeInterface;
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
}
