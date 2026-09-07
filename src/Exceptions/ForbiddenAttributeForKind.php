<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

class ForbiddenAttributeForKind extends RuntimeException
{
    public static function make(string $kind, string $attribute): self
    {
        return new self(
            "Feld '{$attribute}' ist für Terminart '{$kind}' nicht zulässig und wurde gesetzt. "
            .'Entweder gehört der Termin zu einer anderen Art, oder das Feld gehört ins Profil '
            .'dieser Art. Die Liste steht in der EventKind-Klasse unter forbiddenAttributes().'
        );
    }
}
