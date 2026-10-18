<?php

use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Categories\EventCategories;
use Peppermint\Calendar\Exceptions\CategoryNotAllowed;
use Peppermint\Calendar\Models\CalendarCategory;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Tests\Fixtures\PlainCategorisedKind;
use Peppermint\Calendar\Tests\Fixtures\PrivateKind;

/**
 * The assignment event -> category, now kept by the package.
 *
 * Measured across the four products on 18.10.2026: categories worked nowhere.
 * The Manager's `business` kind said it carried them but had no column; the
 * Brain had a column and said it carried none. Each product building its own
 * storage was the arrangement that produced that, so the package keeps it.
 */
beforeEach(function () {
    config()->set('calendar.categories.enabled', true);
    config()->set('calendar.categories.mode', 'closed');
});

function zuordnung(): EventCategories
{
    return app(EventCategories::class);
}

function kategorie(array $attributes = []): CalendarCategory
{
    return CalendarCategory::create(array_merge([
        'slug' => 'kundentermin',
        'label' => 'Kundentermin',
        'is_active' => true,
        'sort_order' => 0,
        'owner_id' => null,
    ], $attributes));
}

function kategorieTermin(string $kind = 'plain_categorised'): CalendarEvent
{
    return CalendarEvent::create([
        'kind' => $kind,
        'title' => 'Termin',
        'owner_id' => 1,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
}

it('keeps several categories on one event', function () {
    kategorie(['slug' => 'kundentermin', 'label' => 'Kundentermin', 'sort_order' => 10]);
    kategorie(['slug' => 'vertrieb', 'label' => 'Vertrieb', 'sort_order' => 20]);

    $event = kategorieTermin();
    zuordnung()->set($event, ['vertrieb', 'kundentermin']);

    expect(zuordnung()->slugsOf($event->refresh()))->toBe(['kundentermin', 'vertrieb']);
});

/**
 * The report counts an event fully in each of its categories while the total
 * counts it once — with a single column that calculation would have been
 * pointless from the start. So the order must come from the maintained list,
 * not from who clicked first.
 */
it('orders by the maintained list, not by the order of assignment', function () {
    kategorie(['slug' => 'spaet', 'label' => 'Spaet', 'sort_order' => 90]);
    kategorie(['slug' => 'frueh', 'label' => 'Frueh', 'sort_order' => 10]);

    $event = kategorieTermin();
    zuordnung()->set($event, ['spaet', 'frueh']);

    expect($event->kindDefinition()->categories($event->refresh()))->toBe(['Frueh', 'Spaet']);
});

it('replaces rather than adds, because a form sends the state it shows', function () {
    kategorie(['slug' => 'a', 'label' => 'A']);
    kategorie(['slug' => 'b', 'label' => 'B']);

    $event = kategorieTermin();
    zuordnung()->set($event, ['a', 'b']);
    zuordnung()->set($event, ['b']);

    expect(zuordnung()->slugsOf($event->refresh()))->toBe(['b']);
});

it('ignores a slug sent twice', function () {
    kategorie(['slug' => 'a', 'label' => 'A']);

    $event = kategorieTermin();
    zuordnung()->set($event, ['a', 'a', ' a ']);

    expect(zuordnung()->slugsOf($event->refresh()))->toBe(['a']);
});

/**
 * A display is not a guard: the call also arrives from an interface or an
 * import, and neither displays anything. So the kind scope has to hold when
 * writing, not only when offering.
 */
it('refuses a category that this kind does not offer', function () {
    kategorie(['slug' => 'sport', 'label' => 'Sport', 'kind' => 'private']);

    expect(fn () => zuordnung()->set(kategorieTermin('plain_categorised'), ['sport']))
        ->toThrow(CategoryNotAllowed::class);
});

it('refuses a slug that does not exist', function () {
    expect(fn () => zuordnung()->set(kategorieTermin(), ['erfunden']))
        ->toThrow(CategoryNotAllowed::class);
});

/**
 * Otherwise rows sit in the table that appear nowhere, and the report counts
 * time no one can see.
 */
it('refuses a kind that carries no categories', function () {
    kategorie();

    expect(fn () => zuordnung()->set(kategorieTermin('private'), ['kundentermin']))
        ->toThrow(CategoryNotAllowed::class);
});

it('says nothing when the feature is switched off', function () {
    config()->set('calendar.categories.enabled', false);

    expect(fn () => zuordnung()->set(kategorieTermin(), ['kundentermin']))
        ->toThrow(CategoryNotAllowed::class);
});

/**
 * A kind that agrees to carry categories needs no storage of its own — that is
 * the whole point of the change. `PlainCategorisedKind` overrides nothing but
 * `usesCategories()`.
 */
it('lets a kind carry categories without building anything itself', function () {
    kategorie();
    $event = kategorieTermin();
    zuordnung()->set($event, ['kundentermin']);

    expect((new PlainCategorisedKind)->categories($event->refresh()))->toBe(['Kundentermin']);
    expect((new PrivateKind)->categories($event))->toBe([]);
});

/**
 * The case that protects an upgrade.
 *
 * The Manager's `business` kind has answered `usesCategories()` with true
 * since December 2025, and its assignment table only exists once it publishes
 * the migration. Without this guard the package would walk into a missing
 * table on the first event it rendered — an upgrade meant to be additive
 * would be an outage.
 */
it('says nothing when this system keeps no assignment table', function () {
    kategorie();
    $event = kategorieTermin();

    Schema::drop(config('calendar.tables.event_categories'));
    CategoryRegistry::forgetAvailability();

    expect((new PlainCategorisedKind)->categories($event))->toBe([]);
});
