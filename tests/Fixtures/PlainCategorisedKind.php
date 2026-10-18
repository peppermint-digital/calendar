<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Kinds\EventKind;

/**
 * Eine Terminart, die Kategorien nur ZUSTIMMT — und sonst nichts baut.
 *
 * Genau das ist ab v0.61.0 der Normalfall: Die Zuordnung haelt das Paket, die
 * Art sagt nur, dass sie Kategorien traegt. `BusinessKind` und
 * `CategorisedKind` ueberschreiben beide `categories()` und pruefen damit ihre
 * eigene Statik — mit ihnen laesst sich der Rueckfall also nicht pruefen.
 */
class PlainCategorisedKind extends EventKind
{
    public function key(): string
    {
        return 'plain_categorised';
    }

    public function label(): string
    {
        return 'Mit Kategorien, ohne Eigenbau';
    }

    public function usesCategories(): bool
    {
        return true;
    }
}
