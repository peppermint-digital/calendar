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
        return 'Privat';
    }

    public function profileModel(): ?string
    {
        return PrivateProfile::class;
    }

    /** Ein privater Termin, den jemand löscht, ist weg — kein Papierkorb. */
    public function usesTrash(): bool
    {
        return false;
    }

    public function forbiddenAttributes(): array
    {
        return ['meeting_url'];
    }
}
