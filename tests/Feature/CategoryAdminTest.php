<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Peppermint\Calendar\Categories\EventCategories;
use Peppermint\Calendar\Http\CalendarRoutes;
use Peppermint\Calendar\Models\CalendarCategory;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Maintaining the shared category list (#8178).
 *
 * The list is the same in four systems and its maintenance existed in none:
 * the Manager had a screen for its own table, the Brain got its seven through
 * a migration, CRM and Verwaltung had the feature switched off. Four screens
 * for one table would be four chances to build the rules differently — and
 * here the rules are the whole point.
 */
beforeEach(function () {
    config()->set('calendar.categories.enabled', true);
    config()->set('calendar.categories.mode', 'closed');
    config()->set('calendar.categories.manage_ability', null);

    // Ohne `web`: Diese Endpunkte sind JSON und brauchen keine Sitzung — und
    // die `web`-Gruppe verlangt einen App-Key, den eine Paketpruefung nicht
    // hat. Die Anmeldeschranke legt ohnehin das Produkt, nicht das Paket.
    Route::prefix('api/calendar')->group(fn () => CalendarRoutes::categories());
});

function pflegeKategorie(array $attributes = []): CalendarCategory
{
    return CalendarCategory::create(array_merge([
        'slug' => 'kundentermin',
        'label' => 'Kundentermin',
        'is_active' => true,
        'sort_order' => 10,
        'owner_id' => null,
    ], $attributes));
}

it('lists everything, inactive included', function () {
    pflegeKategorie(['slug' => 'an', 'label' => 'An']);
    pflegeKategorie(['slug' => 'aus', 'label' => 'Aus', 'is_active' => false]);

    $antwort = $this->getJson('/api/calendar/categories');

    // The maintenance screen must show what exists, not what is selectable:
    // an inactive category invisible in its own administration can never be
    // switched back on.
    expect(collect($antwort->json('categories'))->pluck('slug')->all())
        ->toEqualCanonicalizing(['an', 'aus']);
});

it('derives the slug from the name rather than taking one', function () {
    $this->postJson('/api/calendar/categories', ['label' => 'Wartung & Betrieb'])
        ->assertCreated();

    expect(CalendarCategory::first()->field('slug'))->toBe('wartung_betrieb');
});

it('refuses a second category with the same slug', function () {
    pflegeKategorie(['slug' => 'vertrieb', 'label' => 'Vertrieb']);

    $this->postJson('/api/calendar/categories', ['label' => 'Vertrieb'])
        ->assertStatus(422);

    expect(CalendarCategory::count())->toBe(1);
});

/**
 * The slug is the key four systems agreed on. Pulling it along on a rename
 * dissolves that agreement silently: the category is then called something
 * else in one place and filtering across systems stops finding it.
 */
it('keeps the slug when the name changes', function () {
    $kategorie = pflegeKategorie(['slug' => 'kundentermin', 'label' => 'Kundentermin']);

    $this->patchJson("/api/calendar/categories/{$kategorie->id}", ['label' => 'Kundengespräch'])
        ->assertOk();

    expect($kategorie->refresh()->field('label'))->toBe('Kundengespräch')
        ->and($kategorie->field('slug'))->toBe('kundentermin');
});

it('switches one off and on again', function () {
    $kategorie = pflegeKategorie();

    $this->patchJson("/api/calendar/categories/{$kategorie->id}", ['is_active' => false])->assertOk();
    expect((bool) $kategorie->refresh()->field('is_active'))->toBeFalse();

    $this->patchJson("/api/calendar/categories/{$kategorie->id}", ['is_active' => true])->assertOk();
    expect((bool) $kategorie->refresh()->field('is_active'))->toBeTrue();
});

it('deletes one that carries nothing', function () {
    $kategorie = pflegeKategorie();

    $this->deleteJson("/api/calendar/categories/{$kategorie->id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(CalendarCategory::count())->toBe(0);
});

/**
 * The guard that matters. A deleted category takes the classification of its
 * events with it, and that does not show up when deleting — it shows up weeks
 * later in the report, as missing hours nobody can explain.
 *
 * Measured in the Manager on 18.10.2026: 206 of 359 events carry a category,
 * 199 of them the same one.
 */
it('refuses to delete one that events carry, and says how many', function () {
    $kategorie = pflegeKategorie();

    $event = CalendarEvent::create([
        'kind' => 'plain_categorised',
        'title' => 'Termin',
        'owner_id' => 1,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
    app(EventCategories::class)->set($event, ['kundentermin']);

    $this->deleteJson("/api/calendar/categories/{$kategorie->id}")
        ->assertStatus(409)
        ->assertJson(['deleted' => false, 'in_use' => 1]);

    expect(CalendarCategory::count())->toBe(1);
});

it('refuses a kind that does not exist, so a typo cannot hide a category', function () {
    $this->postJson('/api/calendar/categories', ['label' => 'Test', 'kind' => 'gibt_es_nicht'])
        ->assertStatus(422);
});

it('is gone entirely when categories are switched off in this system', function () {
    config()->set('calendar.categories.enabled', false);

    $this->getJson('/api/calendar/categories')->assertNotFound();
});

/** The package knows the name of the ability, not its meaning. */
it('asks the configured ability and refuses when it says no', function () {
    config()->set('calendar.categories.manage_ability', 'manage-calendar-categories');
    Gate::define('manage-calendar-categories', fn () => false);

    $this->getJson('/api/calendar/categories')->assertForbidden();
    $this->postJson('/api/calendar/categories', ['label' => 'Test'])->assertForbidden();
});

it('does not ask when no ability is configured', function () {
    config()->set('calendar.categories.manage_ability', null);

    $this->getJson('/api/calendar/categories')->assertOk();
});
