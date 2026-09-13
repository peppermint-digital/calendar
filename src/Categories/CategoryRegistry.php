<?php

namespace Peppermint\Calendar\Categories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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
        if (! $this->enabled()) {
            return new Collection;
        }

        return CalendarCategory::query()
            ->visibleTo($userId)
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
     */
    public function resolve(string $label, ?int $userId): CalendarCategory
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

        return $this->create($label, $slug, $userId);
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

            CalendarCategory::query()->create([
                CalendarCategory::column('slug') => $slug,
                CalendarCategory::column('label') => $label,
                CalendarCategory::column('colour') => $entry['colour'] ?? null,
                CalendarCategory::column('sort_order') => $entry['sort_order'] ?? 0,
                CalendarCategory::column('is_active') => true,
                CalendarCategory::column('owner_id') => null,
            ]);

            $created++;
        }

        return $created;
    }

    protected function create(string $label, string $slug, ?int $userId): CalendarCategory
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

        return CalendarCategory::query()->create([
            CalendarCategory::column('slug') => $slug,
            CalendarCategory::column('label') => $label,
            CalendarCategory::column('is_active') => true,
            CalendarCategory::column('sort_order') => 0,
            // Im persoenlichen Modus gehoert die neue Kategorie dieser Person.
            // Genau das haelt die gemeinsame Liste sauber, ohne jemandem das
            // Etikettieren zu verbieten.
            CalendarCategory::column('owner_id') => $mode === CategoryMode::Personal ? $userId : null,
        ]);
    }

    public function slug(string $label): string
    {
        return Str::slug(trim($label), '_');
    }
}
