<?php

namespace Peppermint\Calendar\Categories;

use Illuminate\Support\Collection;
use Peppermint\Calendar\Exceptions\CategoryNotAllowed;
use Peppermint\Calendar\Models\CalendarCategory;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Welche Kategorien an einem Termin haengen — setzen und lesen.
 *
 * ## Warum die Eingrenzung hier wirkt und nicht erst in der Anzeige
 *
 * Die Auswahl zeigt dank {@see CategoryRegistry::forKind()} nur, was die
 * Terminart anbietet. Eine Anzeige ist aber keine Sicherung: Der Aufruf kommt
 * aus einer Maske, aus einer Schnittstelle oder aus einem Import, und die
 * letzten beiden zeigen gar nichts an. Wer die Pruefung nur in die Maske legt,
 * hat sie nicht.
 *
 * Deshalb wird hier gegen dieselbe Liste geprueft, aus der die Auswahl
 * stammt — sonst sind es zwei Antworten auf dieselbe Frage, und sie laufen
 * auseinander.
 *
 * ## Warum ueber den Slug und nicht ueber die ID
 *
 * Die IDs sind je System verschieden, der Slug ist der gemeinsame Schluessel —
 * daran haengt, dass sich ueber die vier Systeme hinweg nach derselben
 * Kategorie filtern laesst. Eine Schnittstelle, die IDs annimmt, bindet den
 * Aufrufer an die Zaehlung einer Datenbank.
 */
class EventCategories
{
    public function __construct(private readonly CategoryRegistry $registry) {}

    /**
     * Setzt die Kategorien dieses Termins auf genau diese Slugs.
     *
     * Ersetzen und nicht ergaenzen: Eine Maske schickt den Zustand, den sie
     * anzeigt. Wer ergaenzen will, liest vorher {@see slugsOf()} — wer
     * ersetzen will und nur ergaenzen kann, bekommt eine Kategorie nie wieder
     * weg.
     *
     * @param  array<int, string>  $slugs
     *
     * @throws CategoryNotAllowed
     */
    public function set(CalendarEvent $event, array $slugs, ?int $userId = null): void
    {
        if (! $this->registry->enabled()) {
            throw CategoryNotAllowed::disabled();
        }

        $art = $event->kindDefinition();

        // Eine Art, die keine Kategorien traegt, soll keine bekommen — sonst
        // liegen Daten in der Tabelle, die nirgends erscheinen, und die
        // Auswertung zaehlt Zeit, die niemand sieht.
        if (! $art->usesCategories()) {
            throw CategoryNotAllowed::disabled();
        }

        $slugs = collect($slugs)
            ->map(fn ($slug) => trim((string) $slug))
            ->filter(fn (string $slug) => $slug !== '')
            ->unique()
            ->values();

        $erlaubt = $this->registry->forKind($art->key(), $userId)
            ->keyBy(fn (CalendarCategory $k) => (string) $k->field('slug'));

        $ids = [];

        foreach ($slugs as $slug) {
            $kategorie = $erlaubt->get($slug);

            if ($kategorie === null) {
                throw CategoryNotAllowed::listIsClosed($slug);
            }

            $ids[] = $kategorie->getKey();
        }

        $event->assignedCategories()->sync($ids);
        $event->unsetRelation('assignedCategories');
    }

    /**
     * Die Slugs der Kategorien dieses Termins.
     *
     * @return array<int, string>
     */
    public function slugsOf(CalendarEvent $event): array
    {
        return $this->categoriesOf($event)
            ->map(fn (CalendarCategory $k) => (string) $k->field('slug'))
            ->all();
    }

    /** @return Collection<int, CalendarCategory> */
    public function categoriesOf(CalendarEvent $event): Collection
    {
        return $event->assignedCategories;
    }
}
