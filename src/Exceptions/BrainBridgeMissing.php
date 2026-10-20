<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

/**
 * Eine Brain-Quelle ist eingestellt, aber die Bruecke fehlt.
 *
 * ## Warum das eine eigene Ausnahme ist
 *
 * Ohne sie waere die Meldung ein `Error: Class
 * "Peppermint\AiBrainBridge\Facades\AiBrain" not found` aus dem Inneren einer
 * Quelle — und die liest sich wie ein kaputtes Paket, nicht wie eine fehlende
 * Abhaengigkeit. Wer sie bekommt, sucht im Kalender statt in der
 * `composer.json`.
 *
 * Die Bruecke steht in `suggest` und nicht in `require` (Regel 7 des
 * Paketvertrags: ein Paket, das eine Abhaengigkeit erzwingt, zwingt sie jedem
 * auf). Diese Ausnahme ist der Preis dafuer, und sie ist es wert: Sie nennt
 * das Paket, den Befehl und die Stelle, an der die Entscheidung getroffen
 * wurde.
 */
class BrainBridgeMissing extends RuntimeException
{
    public static function forCategories(): self
    {
        return new self(
            'Die Kategorienquelle `BrainCategorySource` braucht `peppermint/ai-brain-bridge`. '
            .'Entweder installieren (`composer require peppermint/ai-brain-bridge`) oder in '
            .'`config/calendar.php` unter `categories.source` eine eigene Quelle einstellen.'
        );
    }

    public static function forSources(): self
    {
        return new self(
            'Die Fremdkalender-Quellen (`BrainCalendarSources`) brauchen '
            .'`peppermint/ai-brain-bridge`. Entweder installieren '
            .'(`composer require peppermint/ai-brain-bridge`) oder die Quellen in '
            .'`config/calendar.sources` von Hand einstellen.'
        );
    }
}
