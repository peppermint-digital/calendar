<?php

namespace Peppermint\Calendar\Categories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Peppermint\Calendar\Enums\CategoryMode;
use Peppermint\Calendar\Exceptions\CategoryNotAllowed;
use Peppermint\Calendar\Models\CalendarCategory;

/**
 * Die Kategorienliste — verwaltet, erweiterbar, oder beides.
 *
 * Der Grund, warum das hier liegt und nicht in jeder Anwendung: Ohne
 * Normalisierung entstehen „Sport", „sport" und „Sport " als drei Eintraege,
 * und nach einem halben Jahr ist die Liste unbrauchbar. Das passiert in jedem
 * Produkt gleich — also gehoert die Antwort einmal ins Paket.
 */
class CategoryRegistry
{
    /** Gemerkt je Anfrage — siehe {@see assignmentsAvailable()}. */
    private static ?bool $assignmentsAvailable = null;

    public function enabled(): bool
    {
        return (bool) config('calendar.categories.enabled', false);
    }

    public function mode(): CategoryMode
    {
        return CategoryMode::tryFrom((string) config('calendar.categories.mode', 'personal'))
            ?? CategoryMode::Personal;
    }

    /**
     * Was diese Person auswaehlen kann: die gemeinsamen Kategorien und ihre
     * eigenen. Fremde persoenliche Kategorien sieht niemand — sonst waere
     * „persoenlich" nur ein anderes Wort fuer „unsortiert".
     *
     * @return Collection<int, CalendarCategory>
     */
    public function forUser(?int $userId): Collection
    {
        return $this->forKind(null, $userId);
    }

    /**
     * Was diese Person bei DIESER Terminart auswaehlen kann.
     *
     * Der Unterschied zu {@see forUser()} ist die Eingrenzung auf die Art —
     * und sie ist der Grund, warum es nur eine Liste braucht statt einer je
     * Bereich. Ohne sie stuenden beim Anlegen eines Kundentermins im AI Brain
     * auch „Sport" und „Reise" zur Wahl.
     *
     * `null` grenzt nicht ein: Die Pflegemaske und die Auswertung wollen die
     * ganze Liste. Deshalb ruft `forUser()` genau das auf — ein Aufrufer, der
     * die Art nicht kennt, bekommt dasselbe wie vorher.
     *
     * @return Collection<int, CalendarCategory>
     */
    public function forKind(?string $kind, ?int $userId): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        return CalendarCategory::query()
            ->visibleTo($userId)
            ->forKind($kind)
            ->active()
            ->orderBy(CalendarCategory::column('sort_order'))
            ->orderBy(CalendarCategory::column('label'))
            ->get();
    }

    /**
     * Findet die Kategorie zu einer Eingabe — oder legt sie an, wenn der Modus
     * es erlaubt.
     *
     * Verglichen wird ueber den Slug, nicht ueber das Etikett: Wer „Sport "
     * tippt, meint „Sport", und eine Liste, die beides fuehrt, ist der Anfang
     * vom Wust.
     *
     * Die Terminart wirkt beim ANLEGEN, nicht beim Suchen. Ein Slug meint
     * systemweit eine Kategorie — daran haengt, dass sich ueber die Systeme
     * hinweg nach ihr filtern laesst. Wuerde die Suche nach Art eingrenzen,
     * versuchte sie bei einem Treffer in einer anderen Art ein zweites Mal
     * denselben Slug anzulegen und liefe in den Eindeutigkeitsindex.
     */
    public function resolve(string $label, ?int $userId, ?string $kind = null): CalendarCategory
    {
        if (! $this->enabled()) {
            throw CategoryNotAllowed::disabled();
        }

        $label = trim($label);
        $slug = $this->slug($label);

        if ($slug === '') {
            throw CategoryNotAllowed::empty();
        }

        $existing = CalendarCategory::query()
            ->visibleTo($userId)
            ->where(CalendarCategory::column('slug'), $slug)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->create($label, $slug, $userId, $kind);
    }

    /**
     * Legt den Grundstock an. Idempotent ueber den Slug — die Konfiguration
     * wirkt beim ersten Mal und danach nicht mehr, damit ein umbenanntes
     * Etikett in der Datenbank nicht bei jedem Start zurueckfaellt.
     *
     * @param  array<int, array<string, mixed>>  $defaults
     */
    public function seed(array $defaults): int
    {
        $created = 0;

        foreach ($defaults as $entry) {
            $label = (string) ($entry['label'] ?? '');
            $slug = (string) ($entry['slug'] ?? $this->slug($label));

            if ($slug === '' || $label === '') {
                continue;
            }

            $exists = CalendarCategory::query()
                ->whereNull(CalendarCategory::column('owner_id'))
                ->where(CalendarCategory::column('slug'), $slug)
                ->exists();

            if ($exists) {
                continue;
            }

            // Durch `onlyExistingColumns()`, weil `seed()` auch aus alten
            // Wanderungen gerufen wird — siehe die Begruendung dort.
            CalendarCategory::query()->create(CalendarCategory::onlyExistingColumns([
                CalendarCategory::column('slug') => $slug,
                CalendarCategory::column('label') => $label,
                CalendarCategory::column('colour') => $entry['colour'] ?? null,
                CalendarCategory::column('sort_order') => $entry['sort_order'] ?? 0,
                CalendarCategory::column('is_active') => true,
                CalendarCategory::column('owner_id') => null,
                CalendarCategory::column('kind') => $entry['kind'] ?? null,
            ]));

            $created++;
        }

        return $created;
    }

    protected function create(string $label, string $slug, ?int $userId, ?string $kind = null): CalendarCategory
    {
        $mode = $this->mode();

        if ($mode === CategoryMode::Closed) {
            throw CategoryNotAllowed::listIsClosed($label);
        }

        // Autorisierung gehoert der Anwendung: Das Paket kennt nur den Namen
        // der Berechtigung, nicht ihre Bedeutung. Ohne konfigurierten Namen
        // wird nicht gefragt — ein Paket, das sich selbst Rechte ausdenkt,
        // waere schlimmer als eines, das keine prueft.
        $ability = config('calendar.categories.create_ability');

        if (is_string($ability) && $ability !== '' && Gate::denies($ability)) {
            throw CategoryNotAllowed::notPermitted($label);
        }

        return CalendarCategory::query()->create(CalendarCategory::onlyExistingColumns([
            CalendarCategory::column('slug') => $slug,
            CalendarCategory::column('label') => $label,
            CalendarCategory::column('is_active') => true,
            CalendarCategory::column('sort_order') => 0,
            // Im persoenlichen Modus gehoert die neue Kategorie dieser Person.
            // Genau das haelt die gemeinsame Liste sauber, ohne jemandem das
            // Etikettieren zu verbieten.
            CalendarCategory::column('owner_id') => $mode === CategoryMode::Personal ? $userId : null,
            // Eine bei einer Art entstandene Kategorie gehoert zu ihr. Ohne
            // das waere jede frei getippte Kategorie sofort in allen Arten
            // sichtbar — und der Sinn der Eingrenzung dahin.
            CalendarCategory::column('kind') => $kind,
        ]));
    }

    /**
     * Haelt dieses System die Zuordnung Termin -> Kategorie des Pakets?
     *
     * Die Pruefung ist noetig und nicht vorsichtshalber: Der Manager bejaht
     * bei `business` seit Dezember 2025 `usesCategories()`, und seine
     * Zuordnungstabelle gibt es erst, wenn er die Wanderung veroeffentlicht.
     * Ohne diese Frage wuerde ein Paket-Update dort beim ersten Termin in eine
     * fehlende Tabelle laufen — ein Update, das additiv sein soll, waere ein
     * Ausfall.
     *
     * Einmal je Anfrage gefragt und gemerkt: `Schema::hasTable` ist eine
     * Abfrage, und die Agenda fragt je Termin.
     */
    public function assignmentsAvailable(): bool
    {
        if (self::$assignmentsAvailable !== null) {
            return self::$assignmentsAvailable;
        }

        return self::$assignmentsAvailable = Schema::hasTable(
            config('calendar.tables.event_categories', 'calendar_event_categories'),
        );
    }

    /** Nur fuer Tests: das Gemerkte vergessen. */
    public static function forgetAvailability(): void
    {
        self::$assignmentsAvailable = null;
    }

    public function slug(string $label): string
    {
        return Str::slug(trim($label), '_');
    }
}
