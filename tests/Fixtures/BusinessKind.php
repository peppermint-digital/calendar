<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Kinds\EventKind;

class BusinessKind extends EventKind
{
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
}
