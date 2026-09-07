<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Kinds\EventKind;

class PrivateKind extends EventKind
{
    public function key(): string
    {
        return 'private';
    }

    public function label(): string
    {
        return 'Private';
    }

    public function profileModel(): ?string
    {
        return PrivateProfile::class;
    }

    /** A private appointment someone deletes is gone. No trash bin. */
    public function usesTrash(): bool
    {
        return false;
    }

    public function forbiddenAttributes(): array
    {
        return ['meeting_url'];
    }
}
