<?php

namespace Peppermint\Calendar\Categories;

/**
 * Die gemeinsamen Arbeitskategorien — an einer Stelle.
 *
 * ## Warum das eine eigene Klasse ist
 *
 * Weil zwei Aufrufer sie brauchen und keiner von ihnen sie halten darf: der
 * Befehl {@see \Peppermint\Calendar\Console\SeedWorkCategoriesCommand} fuer
 * den Lauf von Hand, und eine Wanderung im Produkt, damit die Liste auch in
 * Produktion und in jeder neuen Umgebung entsteht, ohne dass jemand sich
 * daran erinnert.
 *
 * Stuende sie in beiden, waere sie zweimal da — und in vier Produkten
 * achtmal. Genau das bricht das Querfiltern: Eine Kategorie, die im einen
 * System `wartung_betrieb` und im anderen `wartung-betrieb` heisst, ist nicht
 * dieselbe, und niemand sieht den Unterschied, bis die Auswertung zwei Zeilen
 * zeigt, wo eine stehen sollte.
 *
 * ## Warum sie im Paket steht und nicht in der Konfiguration
 *
 * `calendar.categories.defaults` gehoert dem Produkt: Dort darf jedes System
 * eigene Kategorien nachlegen. Diese Liste ist das Gegenteil davon — sie ist
 * die Einigung zwischen den Systemen. In vier Konfigurationen waere sie keine.
 *
 * ## Fuer eine Installation ausserhalb von Peppermint
 *
 * Diese Liste wird von der Wanderung des PRODUKTS angelegt, nicht von einer
 * des Pakets. Wer das Paket woanders einbindet, bekommt sie also nicht
 * aufgezwungen — er pflegt seine eigene Liste und ruft diese Klasse nie auf.
 */
final class WorkCategories
{
    /**
     * Die Einigung vom 18.10.2026.
     *
     * Die Slugs tragen UNTERSTRICHE, nicht Bindestriche: Das ist die Form, die
     * {@see CategoryRegistry::slug()} erzeugt (`Str::slug($label, '_')`).
     * Mit Bindestrichen wuerde eine spaeter aus demselben Etikett abgeleitete
     * Kategorie nicht gefunden und ein zweites Mal angelegt.
     *
     * Ohne `kind`: Diese sechs gelten fuer jede Terminart, die Kategorien
     * traegt. Eingegrenzt sind nur die privaten Lebensbereiche im AI Brain,
     * und die gehoeren nicht hierher.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function alle(): array
    {
        return [
            ['slug' => 'kundentermin', 'label' => 'Kundentermin', 'colour' => '#f59e0b', 'sort_order' => 10],
            ['slug' => 'projektarbeit', 'label' => 'Projektarbeit', 'colour' => '#3b82f6', 'sort_order' => 20],
            ['slug' => 'wartung_betrieb', 'label' => 'Wartung & Betrieb', 'colour' => '#64748b', 'sort_order' => 30],
            ['slug' => 'vertrieb', 'label' => 'Vertrieb', 'colour' => '#10b981', 'sort_order' => 40],
            ['slug' => 'intern_orga', 'label' => 'Intern & Orga', 'colour' => '#a855f7', 'sort_order' => 50],
            ['slug' => 'abwesenheit', 'label' => 'Abwesenheit', 'colour' => '#ef4444', 'sort_order' => 60],
        ];
    }
}
