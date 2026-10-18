<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Peppermint\Calendar\Categories\CategorySync;
use Throwable;

/**
 * Die gemeinsame Kategorienliste nachziehen.
 *
 * ## Woher sie kommt, sagt das Produkt
 *
 * `calendar.categories.source` ist ein Aufrufbares, kein Pfad — dieselbe
 * Entscheidung wie bei {@see \Peppermint\Calendar\Sources\EventSource} und bei
 * der Suche nach Planbarem. Die drei Produkte holen sie ueber ihre
 * Brain-Bruecke:
 *
 * ```php
 * // config/calendar.php
 * 'categories' => [
 *     'source' => fn () => \Peppermint\AiBrainBridge\Facades\AiBrain::call(
 *         'list-calendar-categories',
 *     )['categories'] ?? [],
 * ],
 * ```
 *
 * Ein festes Ziel im Paket haette alle gezwungen, dieselbe Nabe zu haben — und
 * das Paket haette etwas ueber die Infrastruktur des Produkts gewusst. Ohne
 * konfigurierte Quelle tut der Befehl nichts und sagt das: Ein Produkt ohne
 * Brain pflegt seine Liste selbst, und das ist kein Fehlerfall.
 *
 * ## Warum ein Fehlschlag nicht als leere Liste gilt
 *
 * Ein Netzfehler oder ein Zeitablauf liefert nichts. Wuerde das als „die Liste
 * ist jetzt leer" gelesen, deaktivierte ein Ausfall der Nabe in einem
 * Durchgang jede gemeinsame Kategorie. Deshalb faengt dieser Befehl die
 * Ausnahme, gibt sie aus und bricht mit Fehlerstatus ab, OHNE abzugleichen —
 * und {@see CategorySync::apply()} verweigert eine leere Quelle noch ein
 * zweites Mal. Zwei Riegel, weil der Schaden still waere.
 */
class PullCategoriesCommand extends Command
{
    protected $signature = 'calendar:pull-categories';

    protected $description = 'Zieht die gemeinsame Kategorienliste aus der Nabe nach';

    public function handle(CategorySync $sync): int
    {
        $quelle = $this->quelle();

        if ($quelle === null) {
            $this->components->info(
                'Keine Quelle konfiguriert (calendar.categories.source) — dieses System '
                .'pflegt seine Kategorien selbst. Nichts getan.',
            );

            return self::SUCCESS;
        }

        try {
            $zeilen = $quelle();
        } catch (Throwable $fehler) {
            // Ausdruecklich NICHT weiterwerfen: Ein geplanter Lauf, der mit
            // einer Ausnahme endet, erzeugt eine Fehlermeldung, die niemand
            // liest. Ein Fehlerstatus plus Ausgabe ist das, was der Zeitplan
            // auswerten kann.
            $this->components->error('Die Quelle ist nicht erreichbar: '.$fehler->getMessage());

            return self::FAILURE;
        }

        if (! is_array($zeilen) || $zeilen === []) {
            $this->components->error(
                'Die Quelle hat nichts geliefert. Nichts abgeglichen — eine leere Antwort '
                .'wird nicht als „die Liste ist jetzt leer" gelesen.',
            );

            return self::FAILURE;
        }

        $bericht = $sync->apply($zeilen);

        $this->components->info(sprintf(
            'Angelegt: %d, angepasst: %d, deaktiviert: %d, unveraendert: %d.',
            $bericht['created'],
            $bericht['updated'],
            $bericht['deactivated'],
            $bericht['untouched'],
        ));

        return self::SUCCESS;
    }

    /**
     * Die Quelle aus der Konfiguration — als KLASSENNAME oder als Aufrufbares.
     *
     * Der Klassenname ist der Weg, den ein Produkt nehmen muss: Eine Closure
     * in einer Konfigurationsdatei laesst `config:cache` scheitern —
     *
     *     LogicException: Your configuration files could not be serialized
     *     because the value at "calendar.categories.source" is non-serializable
     *
     * — und `config:cache` laeuft bei jedem Deploy. Die erste Fassung dieses
     * Befehls nahm nur ein Aufrufbares, und genau daran waere jeder Deploy
     * der drei Produkte gestorben, bevor irgendetwas abgeglichen worden waere.
     *
     * Ein Aufrufbares bleibt zulaessig, weil es in Tests und in einem
     * Dienstanbieter das Bequemere ist. Dort wird nichts gecacht.
     */
    private function quelle(): ?callable
    {
        $quelle = config('calendar.categories.source');

        if (is_string($quelle) && $quelle !== '' && class_exists($quelle)) {
            $aufloesung = app($quelle);

            return is_callable($aufloesung) ? $aufloesung : null;
        }

        return is_callable($quelle) ? $quelle : null;
    }
}
