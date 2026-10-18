<?php

use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Categories\WorkCategories;
use Peppermint\Calendar\Models\CalendarCategory;

/**
 * The six work categories, agreed on 18.10.2026.
 *
 * They live in a package command rather than in four seeders because the slugs
 * have to be identical everywhere: the slug is the key the four systems agree
 * on, the ids differ per database. Four seeders would be four lists, and the
 * first one somebody edits without thinking of the others breaks filtering
 * across systems — silently, because a category called `wartung_betrieb` in
 * one place and `wartung-betrieb` in another is simply not there.
 */
beforeEach(function () {
    config()->set('calendar.categories.enabled', true);
});

it('creates the six and says so', function () {
    $this->artisan('calendar:seed-work-categories')
        ->expectsOutputToContain('Angelegt: 6 von 6')
        ->assertSuccessful();

    expect(CalendarCategory::pluck(CalendarCategory::column('slug'))->all())
        ->toEqualCanonicalizing([
            'kundentermin', 'projektarbeit', 'wartung_betrieb',
            'vertrieb', 'intern_orga', 'abwesenheit',
        ]);
});

/** The slugs use underscores — the shape `CategoryRegistry::slug()` produces. */
it('uses the slug shape the package itself derives', function () {
    $this->artisan('calendar:seed-work-categories')->assertSuccessful();

    $registry = app(CategoryRegistry::class);

    foreach (CalendarCategory::all() as $kategorie) {
        expect($registry->slug((string) $kategorie->field('label')))
            ->toBe((string) $kategorie->field('slug'));
    }
});

/** Runs twice without doubling anything, and without resetting a rename. */
it('can run again without doubling or overwriting', function () {
    $this->artisan('calendar:seed-work-categories')->assertSuccessful();

    CalendarCategory::query()
        ->where(CalendarCategory::column('slug'), 'kundentermin')
        ->update([CalendarCategory::column('label') => 'Kundengespräch']);

    $this->artisan('calendar:seed-work-categories')
        ->expectsOutputToContain('waren schon da')
        ->assertSuccessful();

    expect(CalendarCategory::count())->toBe(6)
        ->and(CalendarCategory::query()
            ->where(CalendarCategory::column('slug'), 'kundentermin')
            ->first()->field('label'))->toBe('Kundengespräch');
});

/** They apply to every kind that carries categories — only the Brain's private areas are scoped. */
it('leaves the kind empty, so every kind that carries categories offers them', function () {
    $this->artisan('calendar:seed-work-categories')->assertSuccessful();

    expect(CalendarCategory::all()->pluck(CalendarCategory::column('kind'))->unique()->all())
        ->toBe([null]);
});

it('refuses when categories are switched off in this system', function () {
    config()->set('calendar.categories.enabled', false);

    $this->artisan('calendar:seed-work-categories')->assertFailed();

    expect(CalendarCategory::count())->toBe(0);
});

/**
 * The regression the package caused on 18.10.2026, and the reason the guard
 * lives here.
 *
 * A migration from September in the AI Brain calls `seed()`. When it was
 * written there was no `kind` column — and at its place in the order there
 * still is none, because the migration that adds it comes later. Every fresh
 * build (tests, a new installation, a new server) broke at setup with
 * "table calendar_categories has no column named kind": 57 tests with zero
 * assertions, pointing at a file unchanged for a month.
 *
 * The migration is frozen in time, the service is not. The package introduced
 * the column, so the package takes the care.
 */
it('seeds from an old migration that predates the kind column', function () {
    // Der Index zuerst — SQLite weist das Entfernen einer Spalte ab, auf die
    // noch ein Index zeigt. Genau so sieht der Zustand aus, in dem die alte
    // Wanderung laeuft: Spalte und Index gibt es dort beide noch nicht.
    Schema::table(config('calendar.tables.categories'), function ($table) {
        $table->dropIndex('cal_categories_kind_active_order_idx');
        $table->dropColumn('kind');
    });

    expect(fn () => app(CategoryRegistry::class)->seed(WorkCategories::alle()))
        ->not->toThrow(Throwable::class);

    expect(CalendarCategory::count())->toBe(6);
});
