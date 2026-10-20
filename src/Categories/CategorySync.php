<?php

namespace Peppermint\Calendar\Categories;

use Peppermint\Calendar\Models\CalendarCategory;

/**
 * Die Kategorienliste eines anderen Systems hier nachziehen.
 *
 * ## Die Richtung
 *
 * Gepflegt wird an EINEM Ort — im AI Brain, weil dort die Nabe ist. Die
 * Produkte ziehen nach. Jedes behaelt dabei seine EIGENE Tabelle: Das Paket
 * soll ohne Brain lauffaehig bleiben, und ein Kalender, der bei einem Ausfall
 * der Nabe keine Kategorien mehr kennt, waere schlechter als einer, der eine
 * etwas alte Liste kennt.
 *
 * Deshalb ist das ein ABGLEICH und kein Fernzugriff.
 *
 * ## Der Slug ist der Schluessel, nicht die ID
 *
 * Die IDs sind je Datenbank verschieden — eine Kategorie, die im Brain die 4
 * hat, ist im CRM die 11. Abgeglichen wird ueber den Slug. Genau daran haengt
 * auch, dass sich systemuebergreifend nach derselben Kategorie filtern laesst.
 *
 * ## Was dieser Abgleich NIE tut
 *
 * **Loeschen.** An einer Kategorie haengen Termine, und eine geloeschte nimmt
 * deren Einordnung mit. Was in der Quelle fehlt, wird DEAKTIVIERT: Es
 * verschwindet aus jeder Auswahl und behaelt seine Geschichte. Gemessen im
 * Manager am 18.10.2026: 206 von 359 Terminen tragen eine Kategorie, 199 davon
 * dieselbe — ein Abgleich, der loescht, haette dort auf einen Schlag 199
 * Einordnungen entfernt, und gemerkt haette man es erst in der Auswertung.
 *
 * **Oertliche Eintraege anfassen.** Eine Kategorie, die es nur hier gibt, ist
 * keine verwaiste Zeile, sondern die Antwort eines Produkts auf eine Frage,
 * die die anderen nicht haben. Der Manager fuehrt „Peppermint" und „CrewTex" —
 * Mandanten, keine Arbeitskategorien. Ein Abgleich, der aufraeumt, was er
 * nicht kennt, haette sie stillgelegt.
 *
 * Das unterscheidet diesen Abgleich von einer Spiegelung: Er fuegt hinzu und
 * gleicht an, er raeumt nicht auf.
 */
class CategorySync
{
    public function __construct(private readonly CategoryRegistry $registry) {}

    /**
     * Gleicht die oertliche Liste an die uebergebene an.
     *
     * @param  array<int, array<string, mixed>>  $quelle  Zeilen mit `slug`,
     *                                                    `label` und optional
     *                                                    `colour`,
     *                                                    `sort_order`, `kind`,
     *                                                    `is_active`.
     * @return array{created: int, updated: int, deactivated: int, untouched: int}
     */
    public function apply(array $quelle): array
    {
        if (! $this->registry->enabled()) {
            return ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'untouched' => 0];
        }

        $bericht = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'untouched' => 0];

        $gueltig = collect($quelle)
            ->filter(fn ($zeile) => is_array($zeile) && ($zeile['slug'] ?? '') !== '' && ($zeile['label'] ?? '') !== '')
            ->keyBy(fn (array $zeile) => (string) $zeile['slug']);

        // Eine leere oder unlesbare Antwort darf NICHTS tun. Sonst wuerde ein
        // Ausfall der Quelle — ein Netzfehler, ein Zeitablauf — als „die
        // Liste ist jetzt leer" gelesen und deaktivierte in einem Durchgang
        // jede gemeinsame Kategorie.
        if ($gueltig->isEmpty()) {
            return $bericht;
        }

        $oertlich = CalendarCategory::query()
            ->whereNull(CalendarCategory::column('owner_id'))
            ->get()
            ->keyBy(fn (CalendarCategory $k) => (string) $k->field('slug'));

        foreach ($gueltig as $slug => $zeile) {
            $vorhanden = $oertlich->get($slug);

            if ($vorhanden === null) {
                CalendarCategory::query()->create(CalendarCategory::onlyExistingColumns([
                    CalendarCategory::column('slug') => $slug,
                    CalendarCategory::column('label') => (string) $zeile['label'],
                    ...$this->farbe($zeile),
                    CalendarCategory::column('sort_order') => (int) ($zeile['sort_order'] ?? 0),
                    CalendarCategory::column('is_active') => (bool) ($zeile['is_active'] ?? true),
                    CalendarCategory::column('kind') => $zeile['kind'] ?? null,
                    CalendarCategory::column('owner_id') => null,
                ]));

                $bericht['created']++;

                continue;
            }

            $neu = CalendarCategory::onlyExistingColumns([
                CalendarCategory::column('label') => (string) $zeile['label'],
                ...$this->farbe($zeile),
                CalendarCategory::column('sort_order') => (int) ($zeile['sort_order'] ?? 0),
                CalendarCategory::column('is_active') => (bool) ($zeile['is_active'] ?? true),
                CalendarCategory::column('kind') => $zeile['kind'] ?? null,
            ]);

            $vorhanden->fill($neu);

            if ($vorhanden->isDirty()) {
                $vorhanden->save();
                $bericht['updated']++;
            } else {
                $bericht['untouched']++;
            }
        }

        // Was die Quelle nicht mehr kennt: deaktivieren, nicht entfernen.
        // Oertliche Eintraege einer Person (`owner_id` gesetzt) sind hier
        // ohnehin nicht dabei — sie gehoeren nicht zur gemeinsamen Liste.
        foreach ($oertlich as $slug => $kategorie) {
            if ($gueltig->has($slug)) {
                continue;
            }

            if (! (bool) $kategorie->field('is_active')) {
                continue;
            }

            // NUR, was einmal aus einem Abgleich kam. Eine Kategorie, die
            // dieses Produkt selbst fuehrt, ist keine verwaiste Zeile.
            if (! $this->kommtAusDemAbgleich($slug)) {
                continue;
            }

            $kategorie->setAttribute(CalendarCategory::column('is_active'), false);
            $kategorie->save();

            $bericht['deactivated']++;
        }

        return $bericht;
    }

    /**
     * Die Farbe — nur wenn die Quelle eine nennt.
     *
     * ## Warum nicht einfach `$zeile['colour'] ?? null`
     *
     * Weil das ein ausdrueckliches NULL in die Spalte schreibt, und das ist
     * etwas anderes als „keine Angabe". Gemessen am 20.10.2026 im Peppermint
     * Manager: Dort heisst die Spalte `color`, ist NOT NULL und hat die
     * Vorgabe `#3b82f6`. Eine Kategorie, die aus der Nabe OHNE Farbe kommt,
     * liess den ganzen Lauf platzen:
     *
     *     SQLSTATE[23000]: NOT NULL constraint failed: event_categories.color
     *
     * Und zwar nicht im `try` um die Quelle herum, sondern danach beim
     * Abgleich — der geplante Lauf endete also mit einer Ausnahme statt mit
     * einem auswertbaren Fehlerstatus.
     *
     * Fehlt der Schluessel, greift beim Anlegen die Vorgabe der Spalte, und
     * beim Abgleich bleibt eine vorhandene Farbe stehen. Das ist auch die
     * richtige Lesart: „Die Quelle nennt keine Farbe" heisst nicht „die Farbe
     * soll weg". `kind` behandeln wir absichtlich NICHT so — dort bedeutet
     * leer etwas, naemlich „fuer jede Terminart".
     *
     * @param  array<string, mixed>  $zeile
     * @return array<string, mixed>
     */
    private function farbe(array $zeile): array
    {
        $farbe = $zeile['colour'] ?? null;

        if ($farbe === null || $farbe === '') {
            return [];
        }

        return [CalendarCategory::column('colour') => $farbe];
    }

    /**
     * Gehoert dieser Slug zur gemeinsamen Liste?
     *
     * Die Frage ist noetig, weil der Abgleich sonst nicht unterscheiden kann
     * zwischen „war mal in der Quelle und ist jetzt weg" und „gab es hier
     * immer schon". Der Manager fuehrt „Peppermint" und „CrewTex"; ein
     * Abgleich, der beides gleich behandelt, legt sie beim ersten Lauf still.
     *
     * Beantwortet wird sie ueber {@see WorkCategories} — die Liste, auf die
     * sich die Systeme geeinigt haben. Das ist absichtlich eng: Wer die
     * gemeinsame Liste erweitert, erweitert sie dort, und bis dahin ruehrt der
     * Abgleich einen unbekannten Eintrag nicht an.
     */
    private function kommtAusDemAbgleich(string $slug): bool
    {
        return in_array($slug, array_column(WorkCategories::alle(), 'slug'), true);
    }
}
