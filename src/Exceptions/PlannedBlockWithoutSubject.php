<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

/**
 * Ein geplanter Block ohne seinen Gegenstand.
 *
 * Die Form sagt, was ein solcher Block IST: die Planung einer Sache. Ohne die
 * Sache ist er ein Eintrag, den danach niemand mehr zuordnen kann — und weil
 * ein geplanter Block keinen Papierkorb hat, faellt das erst auf, wenn jemand
 * ihn im Raster sieht und nicht weiss, wofuer er steht.
 *
 * Bis zum 14.10.2026 hat jedes System diesen Riegel selbst gebaut, mit eigener
 * Ausnahmeklasse und eigenem Wortlaut. Drei Abschriften derselben Regel.
 */
class PlannedBlockWithoutSubject extends RuntimeException
{
    public static function make(string $kind, string $key): self
    {
        return new self(
            "A planned block of kind [{$kind}] needs its subject: [{$key}] is empty."
        );
    }
}
