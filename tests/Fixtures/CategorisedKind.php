<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Eine Terminart, deren Kategorien JE TERMIN verschieden sind.
 *
 * `BusinessKind` hält sie statisch — für die meisten Prüfungen reicht das, für
 * eine Auswertung nicht: Dort ist der ganze Sinn, dass verschiedene Termine in
 * verschiedene Kategorien fallen. Eine eigene Art statt eines Umbaus an der
 * geteilten, damit kein bestehender Test seine Annahme verliert.
 */
class CategorisedKind extends EventKind
{
    /** @var array<string, array<int, string>> Titel des Termins → Kategorien */
    public static array $nachTitel = [];

    /**
     * Titel des Termins → Vorgang.
     *
     * Dazugekommen fuer `subjects` (#8307): Dort geht es um Termine, die
     * BEIDES haben — eine Kategorie und eine Herkunft. `GroupedBlockKind`
     * kann nur die Herkunft, diese Art nun beides.
     *
     * @var array<string, string>
     */
    public static array $vorgangNachTitel = [];

    public function key(): string
    {
        return 'categorised';
    }

    public function label(): string
    {
        return 'Mit Kategorien';
    }

    public function usesCategories(): bool
    {
        return true;
    }

    /**
     * Über den TITEL und nicht über die Kennung: Eine Prüfung legt ihre
     * Termine an und kennt die Kennungen erst danach — der Titel steht schon
     * beim Schreiben fest und macht die Erwartung lesbar.
     *
     * @return array<int, string>
     */
    public function categories(CalendarEvent $event): array
    {
        return self::$nachTitel[(string) $event->title] ?? [];
    }

    public function reportGroup(CalendarEvent $event): ?string
    {
        return self::$vorgangNachTitel[(string) $event->title] ?? null;
    }
}
