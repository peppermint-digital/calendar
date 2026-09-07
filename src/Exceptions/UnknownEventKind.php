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
        $list = $known === [] ? '(keine registriert)' : implode(', ', $known);

        return new self(
            "Terminart '{$key}' ist nicht registriert. Bekannt: {$list}. "
            .'Arten werden in config/calendar.php unter "kinds" eingetragen oder über '
            .'EventKindRegistry::register() angemeldet — das Paket bringt selbst keine mit.'
        );
    }
}
