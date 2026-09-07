<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Peppermint\Calendar\Sources\EventSource;
use Peppermint\Calendar\Sources\ExternalEvent;

/** Stands in for another system's calendar. */
class DemoSource extends EventSource
{
    public static bool $available = true;

    public static bool $throws = false;

    public function key(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return 'Demo system';
    }

    public function isAvailable(): bool
    {
        return self::$available;
    }

    public function events(int $userId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        if (self::$throws) {
            throw new \RuntimeException('remote system unreachable');
        }

        return [
            new ExternalEvent(
                sourceKey: $this->key(),
                id: '42',
                title: 'Meeting elsewhere',
                startsAt: CarbonImmutable::parse('2026-09-08 09:00'),
                endsAt: CarbonImmutable::parse('2026-09-08 10:00'),
                location: 'Remote',
            ),
        ];
    }
}
