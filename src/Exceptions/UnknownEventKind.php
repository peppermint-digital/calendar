<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

class UnknownEventKind extends RuntimeException
{
    /**
     * @param  array<int, string>  $known
     */
    public static function for(string $key, array $known): self
    {
        return new self(trans('calendar::calendar.kind.unknown', [
            'given' => $key,
            'known' => $known === [] ? trans('calendar::calendar.kind.none_registered') : implode(', ', $known),
        ]));
    }
}
