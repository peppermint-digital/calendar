<?php

namespace Peppermint\Calendar\Exceptions;

use Peppermint\Calendar\Schema\CalendarSchema;
use RuntimeException;

/**
 * Ein Name, den MySQL nicht annimmt — gemerkt, bevor die Migration laeuft.
 *
 * Die Meldung nennt den Ausweg, weil die naheliegende Loesung („dann gib dem
 * Index von Hand einen kurzen Namen") die naechste Spalte wieder hineinlaufen
 * laesst. Kuerzer benennen ist der Ausweg, nicht ein Handname.
 */
class IdentifierTooLong extends RuntimeException
{
    public static function table(string $tabelle): self
    {
        return new self(sprintf(
            'Table name [%s] is %d characters — MySQL allows %d. Shorten the event kind key.',
            $tabelle,
            strlen($tabelle),
            CalendarSchema::IDENTIFIER_LIMIT,
        ));
    }

    public static function index(string $name, string $tabelle, string $spalte): self
    {
        return new self(sprintf(
            'Index name [%s] would be %d characters — MySQL allows %d. SQLite does not enforce this, '.
            'so a green test suite says nothing about it. Shorten the column [%s]: in a table called [%s] '.
            'the subject rarely needs its full name again (e.g. `booking_id` instead of `resource_booking_id`).',
            $name,
            strlen($name),
            CalendarSchema::IDENTIFIER_LIMIT,
            $spalte,
            $tabelle,
        ));
    }
}
