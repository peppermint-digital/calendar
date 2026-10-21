<?php

namespace Peppermint\Calendar\Categories;

use Peppermint\Calendar\Exceptions\BrainBridgeMissing;

/**
 * Holt die gemeinsame Kategorienliste aus dem AI Brain.
 *
 * ## Warum das jetzt im Paket steht
 *
 * Bis zum 20.10.2026 lag diese Klasse **dreimal** als
 * `App\Kalender\KategorienAusDemBrain` — in Manager, CRM und Verwaltung,
 * byteweise identisch (gemessen: 236 Zeichen Code, derselbe md5 in allen
 * drei). Das ist Frage 3 des Paketvertrags („Schreibt jedes Produkt denselben
 * Verdrahtungscode?"), dreimal mit ja beantwortet.
 *
 * Sechs Zeilen Code, dreimal gepflegt. Ein Produkt stellt sie jetzt nur noch
 * ein:
 *
 *     'categories' => ['source' => \Peppermint\Calendar\Categories\BrainCategorySource::class]
 *
 * ## Warum eine Klasse und keine Closure
 *
 * `config:cache` laeuft bei jedem Deploy und kann eine Closure nicht
 * serialisieren:
 *
 *     LogicException: Your configuration files could not be serialized
 *     because the value at "calendar.categories.source" is non-serializable
 *
 * Ein Klassenname ist eine Zeichenkette und uebersteht das. Aufgeloest wird er
 * ueber den Dienstbehaelter.
 *
 * ## Was hier NICHT passiert
 *
 * Kein Abgleich und keine Entscheidung darueber, was mit der Liste geschieht —
 * das macht {@see CategorySync}. Diese Klasse ist nur die Leitung.
 *
 * Wirft der Aufruf, faengt der Befehl das und bricht ab, OHNE abzugleichen —
 * eine leere Antwort wird nicht als „die Liste ist jetzt leer" gelesen.
 *
 * ## Warum die Bruecke optional bleibt
 *
 * Regel 7 des Paketvertrags: Ein Paket, das eine Abhaengigkeit erzwingt,
 * zwingt sie jedem auf. `peppermint/ai-brain-bridge` steht deshalb in
 * `suggest`, nicht in `require` — und wer diese Quelle einstellt, ohne die
 * Bruecke zu haben, bekommt eine Meldung, die sagt was fehlt, statt eines
 * „Class not found" aus der Tiefe.
 *
 * Das ist kein Hintertuerchen fuer einen Betrieb ohne Nabe: Bei uns ist das
 * Brain gesetzt. Es ist die Zusicherung, dass das Paket fuer sich
 * installierbar bleibt.
 */
class BrainCategorySource
{
    /**
     * Wie das Werkzeug in der Nabe heisst.
     *
     * **Mit `-tool` am Ende.** Die Nabe benennt jedes ihrer Werkzeuge so, und
     * jeder andere Aufrufer im Haus haelt sich daran — `list-projects-tool`,
     * `create-task-tool`, `channel-list-tool`. Hier stand bis zum 21.10.2026
     * der kurze Name, und der Abruf scheiterte bei jedem Lauf:
     *
     *     MCP tools/call error: Tool [list-calendar-categories] not found.
     *     Did you mean [list-calendar-categories-tool]?
     *
     * Aufgefallen ist es erst, als das Werkzeug ueberhaupt registriert war —
     * vorher verdeckte ein zweiter Fehler diesen hier (Verwaltung Bug #1065).
     * Zwei Ursachen hinter einer Meldung, und die erste zu beheben machte die
     * zweite sichtbar.
     *
     * Als Konstante und nicht als Zeichenkette im Aufruf, damit der Name
     * pruefbar ist: Die Suite dieses Pakets hat die Bruecke nicht installiert
     * und kann den Aufruf selbst nicht nachstellen.
     */
    public const WERKZEUG = 'list-calendar-categories-tool';

    /** @return array<int, array<string, mixed>> */
    public function __invoke(): array
    {
        $bruecke = '\\Peppermint\\AiBrainBridge\\Facades\\AiBrain';

        if (! class_exists($bruecke)) {
            throw BrainBridgeMissing::forCategories();
        }

        $antwort = $bruecke::call(self::WERKZEUG);

        return $antwort['categories'] ?? [];
    }
}
