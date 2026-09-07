<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

class ForbiddenAttributeForKind extends RuntimeException
{
    public static function make(string $kind, string $attribute): self
    {
        return new self(trans('calendar::calendar.kind.forbidden_attribute', [
            'attribute' => $attribute,
            'kind' => $kind,
        ]));
    }
}
