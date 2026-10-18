<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Categories\WorkCategories;

/**
 * Legt die gemeinsamen Arbeitskategorien an.
 *
 * ## Warum ein Befehl im Paket und nicht ein Seeder je Produkt
 *
 * Weil die Slugs ueberall dieselben sein MUESSEN. Der Slug ist der
 * Schluessel, auf den sich die vier Systeme einigen — die IDs sind je
 * Datenbank verschieden. Vier Seeder waeren vier Listen, und die erste, die
 * jemand bearbeitet, ohne an die anderen zu denken, bricht das
 * Querfiltern — still, denn eine Kategorie, die im einen System
 * `wartung_betrieb` und im anderen `wartung-betrieb` heisst, ist einfach
 * nicht dabei.
 *
 * Die Liste selbst steht in {@see WorkCategories} — sie hat zwei Aufrufer:
 * diesen Befehl fuer den Lauf von Hand und eine Wanderung im Produkt, damit
 * sie auch in Produktion und in jeder neuen Umgebung entsteht.
 *
 * ## Mehrfach ausfuehrbar
 *
 * `CategoryRegistry::seed()` ist ueber den Slug idempotent und laesst
 * bestehende Eintraege unangetastet. Ein zweiter Lauf legt nichts doppelt an
 * und setzt auch kein umbenanntes Etikett zurueck — wer „Kundentermin" in
 * „Kundengespraech" umbenennt, behaelt das.
 */
class SeedWorkCategoriesCommand extends Command
{
    protected $signature = 'calendar:seed-work-categories';

    protected $description = 'Legt die gemeinsamen Arbeitskategorien an (idempotent)';

    public function handle(CategoryRegistry $registry): int
    {
        if (! $registry->enabled()) {
            $this->components->error(
                'Kategorien sind in diesem System aus (calendar.categories.enabled). '
                .'Nichts angelegt.',
            );

            return self::FAILURE;
        }

        $angelegt = $registry->seed(WorkCategories::alle());

        $this->components->info(
            $angelegt === 0
                ? 'Alle sechs Arbeitskategorien waren schon da.'
                : "Angelegt: {$angelegt} von ".count(WorkCategories::alle()).'.',
        );

        foreach ($registry->forKind(null, null) as $kategorie) {
            $this->line(sprintf(
                '  %-20s %-22s %s',
                $kategorie->field('slug'),
                $kategorie->field('label'),
                $kategorie->field('kind') ?? '(jede Art)',
            ));
        }

        return self::SUCCESS;
    }
}
