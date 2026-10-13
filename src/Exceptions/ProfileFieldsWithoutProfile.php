<?php

namespace Peppermint\Calendar\Exceptions;

use LogicException;

/**
 * Eine Art nennt Profilfelder, aber kein Profilmodell.
 *
 * Kein Zustand, der sich still korrigieren laesst: Die flach uebergebenen
 * Werte haetten nirgends einen Platz, und wer sie uebergibt, glaubt sie
 * gespeichert. Lieber hier laut als dort leer.
 */
class ProfileFieldsWithoutProfile extends LogicException
{
    public static function make(string $kind): self
    {
        return new self(
            "Event kind [{$kind}] names profileFields() but no profileModel() — the values would have nowhere to go."
        );
    }
}
