<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Models\CalendarEvent;

class BusinessKind extends EventKind
{
    /** @var array<int, string> */
    public static array $categories = [];

    public function key(): string
    {
        return 'business';
    }

    public function label(): string
    {
        return 'Business';
    }

    public function profileModel(): ?string
    {
        return BusinessProfile::class;
    }

    public function trashRetentionDays(): ?int
    {
        return 30;
    }

    public function usesCategories(): bool
    {
        return true;
    }

    public function categories(CalendarEvent $event): array
    {
        return self::$categories;
    }
}
