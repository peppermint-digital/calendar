<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

/**
 * Ein Wert wurde uebergeben, den diese Terminart nirgends ablegen kann.
 *
 * Weder eine Spalte der Kerntabelle noch ein Feld im Profil der Art. Vor dem
 * Umzug der Felder gab es diesen Fall nicht: Die Kerntabelle war breit genug
 * fuer alles, und ein Wert, der keiner Art gehoerte, landete trotzdem
 * irgendwo. Genau das war das Problem.
 *
 * Laut, nicht still: Wer den Wert uebergibt, haelt ihn fuer gespeichert.
 */
class AttributeWithoutHome extends RuntimeException
{
    public static function make(string $kind, string $attribute): self
    {
        return new self(
            "Event kind [{$kind}] has no place for [{$attribute}] — neither a core column nor one of its profileFields(). ".
            'Add it to the kind\'s profile, or stop sending it for this kind.'
        );
    }
}
