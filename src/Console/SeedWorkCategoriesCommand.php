<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Peppermint\Calendar\Categories\CategoryRegistry;

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
 * ## Warum die Liste hier steht und nicht in der Konfiguration
 *
 * Die Konfiguration hat schon `categories.defaults`, und die gehoert dem
 * Produkt: Dort darf jedes System eigene Kategorien nachlegen. Dieser
 * Grundstock ist das Gegenteil davon — er ist die Einigung. Steht er in vier
 * Konfigurationen, ist er keine.
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

    /**
     * Die Einigung vom 18.10.2026.
     *
     * Die Slugs tragen UNTERSTRICHE, nicht Bindestriche — das ist die Form,
     * die `CategoryRegistry::slug()` erzeugt (`Str::slug($label, '_')`). Mit
     * Bindestrichen wuerde eine spaeter aus demselben Etikett abgeleitete
     * Kategorie nicht gefunden und ein zweites Mal angelegt.
     *
     * Ohne `kind`: Diese sechs gelten fuer jede Terminart, die Kategorien
     * traegt. Nur die privaten Lebensbereiche im AI Brain sind auf `private`
     * eingegrenzt — und die gehoeren nicht zu dieser Liste.
     *
     * @var array<int, array<string, mixed>>
     */
    private const KATEGORIEN = [
        ['slug' => 'kundentermin', 'label' => 'Kundentermin', 'colour' => '#f59e0b', 'sort_order' => 10],
        ['slug' => 'projektarbeit', 'label' => 'Projektarbeit', 'colour' => '#3b82f6', 'sort_order' => 20],
        ['slug' => 'wartung_betrieb', 'label' => 'Wartung & Betrieb', 'colour' => '#64748b', 'sort_order' => 30],
        ['slug' => 'vertrieb', 'label' => 'Vertrieb', 'colour' => '#10b981', 'sort_order' => 40],
        ['slug' => 'intern_orga', 'label' => 'Intern & Orga', 'colour' => '#a855f7', 'sort_order' => 50],
        ['slug' => 'abwesenheit', 'label' => 'Abwesenheit', 'colour' => '#ef4444', 'sort_order' => 60],
    ];

    public function handle(CategoryRegistry $registry): int
    {
        if (! $registry->enabled()) {
            $this->components->error(
                'Kategorien sind in diesem System aus (calendar.categories.enabled). '
                .'Nichts angelegt.',
            );

            return self::FAILURE;
        }

        $angelegt = $registry->seed(self::KATEGORIEN);

        $this->components->info(
            $angelegt === 0
                ? 'Alle sechs Arbeitskategorien waren schon da.'
                : "Angelegt: {$angelegt} von ".count(self::KATEGORIEN).'.',
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
