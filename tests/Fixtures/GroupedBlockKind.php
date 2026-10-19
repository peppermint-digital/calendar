<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Eine Terminart, die KEINE Kategorie traegt, aber sagen kann, worueber ihre
 * Zeit in der Auswertung gruppiert gehoert.
 *
 * Das ist die Lage eines Zeitblocks: Er zeigt auf eine Aufgabe, traegt
 * deshalb kein Etikett — und seine Zeit soll trotzdem nicht in einer einzigen
 * Sammelzeile verschwinden.
 */
class GroupedBlockKind extends EventKind
{
    /** @var array<string, string|null> Titel des Termins → Gruppe */
    public static array $nachTitel = [];

    public function key(): string
    {
        return 'grouped_block';
    }

    public function label(): string
    {
        return 'Zeitblock mit Gruppe';
    }

    public function reportGroup(CalendarEvent $event): ?string
    {
        return self::$nachTitel[(string) $event->title] ?? null;
    }
}
