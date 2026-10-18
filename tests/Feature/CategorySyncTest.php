<?php

use Peppermint\Calendar\Categories\CategorySync;
use Peppermint\Calendar\Categories\WorkCategories;
use Peppermint\Calendar\Models\CalendarCategory;

/**
 * Pulling the maintained list into a product (#8175).
 *
 * Maintained in one place — the AI Brain, because that is the hub. The
 * products pull. Each keeps its OWN table: the package has to stay usable
 * without the Brain, and a calendar that knows no categories when the hub is
 * down would be worse than one that knows a slightly old list.
 */
beforeEach(function () {
    config()->set('calendar.categories.enabled', true);
});

function abgleich(): CategorySync
{
    return app(CategorySync::class);
}

function quelle(array $over = []): array
{
    return array_merge([
        'slug' => 'kundentermin',
        'label' => 'Kundentermin',
        'colour' => '#f59e0b',
        'sort_order' => 10,
        'is_active' => true,
        'kind' => null,
    ], $over);
}

function hier(array $attributes = []): CalendarCategory
{
    return CalendarCategory::create(array_merge([
        'slug' => 'kundentermin',
        'label' => 'Kundentermin',
        'is_active' => true,
        'sort_order' => 10,
        'owner_id' => null,
    ], $attributes));
}

it('creates what is missing', function () {
    $bericht = abgleich()->apply([quelle(), quelle(['slug' => 'vertrieb', 'label' => 'Vertrieb'])]);

    expect($bericht['created'])->toBe(2)
        ->and(CalendarCategory::pluck(CalendarCategory::column('slug'))->all())
        ->toEqualCanonicalizing(['kundentermin', 'vertrieb']);
});

/** The ids differ per database — a category that is 4 in the Brain is 11 here. */
it('matches on the slug, not on the id', function () {
    $vorhanden = hier(['slug' => 'vertrieb', 'label' => 'Alt']);

    abgleich()->apply([quelle(['slug' => 'vertrieb', 'label' => 'Vertrieb'])]);

    expect(CalendarCategory::count())->toBe(1)
        ->and($vorhanden->refresh()->field('label'))->toBe('Vertrieb');
});

it('reports what it left alone', function () {
    hier(['slug' => 'kundentermin', 'label' => 'Kundentermin', 'sort_order' => 10]);

    $bericht = abgleich()->apply([quelle(['colour' => null])]);

    expect($bericht)->toMatchArray(['created' => 0, 'updated' => 0, 'untouched' => 1]);
});

/**
 * The rule that makes this a sync and not a mirror. A deleted category takes
 * the classification of its events with it — measured in the Manager: 206 of
 * 359 events carry one, 199 of them the same. Deleting on sync would have
 * removed 199 classifications in one go, noticed weeks later in the report.
 */
it('deactivates what the source dropped instead of deleting it', function () {
    hier(['slug' => 'vertrieb', 'label' => 'Vertrieb']);

    $bericht = abgleich()->apply([quelle()]);

    $weg = CalendarCategory::query()->where(CalendarCategory::column('slug'), 'vertrieb')->first();

    expect($bericht['deactivated'])->toBe(1)
        ->and($weg)->not->toBeNull()
        ->and((bool) $weg->field('is_active'))->toBeFalse();
});

/**
 * A category only this product keeps is not an orphan — it is its answer to a
 * question the others do not have. The Manager keeps "Peppermint" and
 * "CrewTex", which are clients. A sync that tidies up what it does not know
 * would have switched them off on its first run.
 */
it('leaves a local category alone, even when the source never mentions it', function () {
    hier(['slug' => 'peppermint', 'label' => 'Peppermint']);
    hier(['slug' => 'crewtex', 'label' => 'CrewTex']);

    $bericht = abgleich()->apply([quelle()]);

    expect($bericht['deactivated'])->toBe(0)
        ->and(CalendarCategory::query()->where(CalendarCategory::column('is_active'), true)->count())->toBe(3);
});

/**
 * The guard against a failing source. A network error or a timeout must not be
 * read as "the list is empty now" — that would switch off every shared
 * category in one pass.
 */
it('does nothing at all when the source comes back empty', function () {
    hier();

    $bericht = abgleich()->apply([]);

    expect($bericht)->toMatchArray(['created' => 0, 'updated' => 0, 'deactivated' => 0])
        ->and((bool) CalendarCategory::first()->field('is_active'))->toBeTrue();
});

it('skips rows without a slug or a label', function () {
    $bericht = abgleich()->apply([
        ['slug' => '', 'label' => 'Ohne Slug'],
        ['slug' => 'ohne_label', 'label' => ''],
        quelle(),
    ]);

    expect($bericht['created'])->toBe(1)
        ->and(CalendarCategory::pluck(CalendarCategory::column('slug'))->all())->toBe(['kundentermin']);
});

/** Personal categories are not part of the shared list and are never touched. */
it('never touches a personal category', function () {
    $meine = hier(['slug' => 'meine', 'label' => 'Meine', 'owner_id' => 7]);

    abgleich()->apply([quelle()]);

    expect((bool) $meine->refresh()->field('is_active'))->toBeTrue();
});

it('carries the kind along', function () {
    abgleich()->apply([quelle(['slug' => 'sport', 'label' => 'Sport', 'kind' => 'private'])]);

    expect(CalendarCategory::query()->where(CalendarCategory::column('slug'), 'sport')->first()->field('kind'))
        ->toBe('private');
});

it('does nothing when categories are switched off in this system', function () {
    config()->set('calendar.categories.enabled', false);

    expect(abgleich()->apply([quelle()]))->toMatchArray(['created' => 0]);
    expect(CalendarCategory::count())->toBe(0);
});

/** The six are what "comes from the sync" means — nothing wider. */
it('deactivates only slugs that belong to the shared list', function () {
    foreach (WorkCategories::alle() as $eintrag) {
        hier(['slug' => $eintrag['slug'], 'label' => $eintrag['label']]);
    }
    hier(['slug' => 'nur_hier', 'label' => 'Nur hier']);

    $bericht = abgleich()->apply([quelle()]);

    // Fünf der sechs fehlen in der Quelle, `nur_hier` gehört nicht dazu.
    expect($bericht['deactivated'])->toBe(5)
        ->and((bool) CalendarCategory::query()
            ->where(CalendarCategory::column('slug'), 'nur_hier')
            ->first()->field('is_active'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('says so and does nothing when no source is configured', function () {
    config()->set('calendar.categories.source', null);

    $this->artisan('calendar:pull-categories')
        ->expectsOutputToContain('Keine Quelle konfiguriert')
        ->assertSuccessful();
});

/**
 * A network error or a timeout returns nothing. Read as "the list is empty
 * now", one hub outage would switch off every shared category in a single
 * pass — so the command refuses, and `apply()` refuses a second time.
 */
it('fails without syncing when the source throws', function () {
    hier();
    config()->set('calendar.categories.source', fn () => throw new RuntimeException('Zeitablauf'));

    $this->artisan('calendar:pull-categories')
        ->expectsOutputToContain('nicht erreichbar')
        ->assertFailed();

    expect((bool) CalendarCategory::first()->field('is_active'))->toBeTrue();
});

it('fails without syncing when the source returns nothing', function () {
    hier();
    config()->set('calendar.categories.source', fn () => []);

    $this->artisan('calendar:pull-categories')->assertFailed();

    expect((bool) CalendarCategory::first()->field('is_active'))->toBeTrue();
});

it('syncs what the configured source hands it', function () {
    config()->set('calendar.categories.source', fn () => [quelle(), quelle(['slug' => 'vertrieb', 'label' => 'Vertrieb'])]);

    $this->artisan('calendar:pull-categories')
        ->expectsOutputToContain('Angelegt: 2')
        ->assertSuccessful();

    expect(CalendarCategory::count())->toBe(2);
});

/**
 * A closure in a config file makes `config:cache` fail — and that runs on
 * every deployment:
 *
 *     LogicException: Your configuration files could not be serialized
 *     because the value at "calendar.categories.source" is non-serializable
 *
 * So the config carries the NAME of an invokable class. Measured before this
 * was fixed: `php artisan config:cache` in the CRM threw exactly that.
 */
it('accepts the name of an invokable class as its source', function () {
    config()->set('calendar.categories.source', QuelleAlsKlasse::class);

    $this->artisan('calendar:pull-categories')
        ->expectsOutputToContain('Angelegt: 1')
        ->assertSuccessful();

    expect(CalendarCategory::query()->where(CalendarCategory::column('slug'), 'kundentermin')->exists())->toBeTrue();
});

it('treats a class that is not invokable as no source at all', function () {
    config()->set('calendar.categories.source', QuelleOhneAufruf::class);

    $this->artisan('calendar:pull-categories')
        ->expectsOutputToContain('Keine Quelle konfiguriert')
        ->assertSuccessful();
});

class QuelleAlsKlasse
{
    /** @return array<int, array<string, mixed>> */
    public function __invoke(): array
    {
        return [['slug' => 'kundentermin', 'label' => 'Kundentermin']];
    }
}

class QuelleOhneAufruf
{
}
