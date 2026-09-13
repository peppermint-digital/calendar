<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Exceptions\CategoryNotAllowed;
use Peppermint\Calendar\Ics\IcsCategories;
use Peppermint\Calendar\Ics\IcsExporter;
use Peppermint\Calendar\Models\CalendarCategory;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Tests\Fixtures\BusinessKind;

beforeEach(function () {
    config()->set('calendar.categories.enabled', true);
    config()->set('calendar.categories.mode', 'personal');
    config()->set('calendar.categories.create_ability', null);
    BusinessKind::$categories = [];
});

function registry(): CategoryRegistry
{
    return app(CategoryRegistry::class);
}

function category(array $attributes = []): CalendarCategory
{
    return CalendarCategory::create(array_merge([
        'slug' => 'sport',
        'label' => 'Sport',
        'is_active' => true,
        'sort_order' => 0,
        'owner_id' => null,
    ], $attributes));
}

it('offers the shared list and a person’s own, and nobody else’s', function () {
    category(['slug' => 'shared', 'label' => 'Shared']);
    category(['slug' => 'mine', 'label' => 'Mine', 'owner_id' => 1]);
    category(['slug' => 'theirs', 'label' => 'Theirs', 'owner_id' => 2]);

    expect(registry()->forUser(1)->pluck('slug')->all())
        ->toEqualCanonicalizing(['shared', 'mine']);
});

it('leaves out what an administrator switched off', function () {
    category(['slug' => 'retired', 'label' => 'Retired', 'is_active' => false]);

    expect(registry()->forUser(1))->toHaveCount(0);
});

it('finds an existing category however it was typed', function () {
    // Ohne diese Normalisierung entstehen „Sport", „sport" und „Sport " als
    // drei Eintraege — und nach einem halben Jahr ist die Liste unbrauchbar.
    $existing = category();

    foreach (['Sport', 'sport', '  SPORT  '] as $typed) {
        expect(registry()->resolve($typed, 1)->id)->toBe($existing->id);
    }

    expect(CalendarCategory::count())->toBe(1);
});

it('refuses a new category when the list is closed', function () {
    config()->set('calendar.categories.mode', 'closed');

    expect(fn () => registry()->resolve('Brandneu', 1))
        ->toThrow(CategoryNotAllowed::class);
});

it('gives a freely typed category to the person, not to everyone', function () {
    // Der Kern der Sache: Erweiterbar bleiben, ohne dass die gemeinsame Liste
    // zumuellt. Ein Administrator kann spaeter hochstufen — bewusst.
    $created = registry()->resolve('Kartenabend', 7);

    expect($created->owner_id)->toBe(7)
        ->and($created->slug)->toBe('kartenabend')
        ->and(registry()->forUser(8)->pluck('slug')->all())->not->toContain('kartenabend');
});

it('gives it to everyone when the list is open', function () {
    config()->set('calendar.categories.mode', 'open');

    expect(registry()->resolve('Teamsache', 7)->owner_id)->toBeNull();
});

it('asks the application before creating one, when it named an ability', function () {
    config()->set('calendar.categories.create_ability', 'calendar.categories.create');
    // Der Parameter muss nullable typisiert sein, sonst lehnt Laravel die
    // Faehigkeit fuer Gaeste generell ab — und der Test waere in beide
    // Richtungen gruen, ohne je die Berechtigung zu pruefen.
    Gate::define('calendar.categories.create', fn (?Authenticatable $user) => false);

    expect(fn () => registry()->resolve('Verboten', 1))
        ->toThrow(CategoryNotAllowed::class);

    Gate::define('calendar.categories.create', fn (?Authenticatable $user) => true);

    expect(registry()->resolve('Erlaubt', 1)->slug)->toBe('erlaubt');
});

it('seeds a starting set once and not again', function () {
    $defaults = [
        ['slug' => 'health', 'label' => 'Gesundheit', 'colour' => '#10b981'],
        ['slug' => 'sport', 'label' => 'Sport'],
    ];

    expect(registry()->seed($defaults))->toBe(2);

    // Ein umbenanntes Etikett darf beim naechsten Start nicht zurueckfallen.
    CalendarCategory::where('slug', 'sport')->update(['label' => 'Bewegung']);

    expect(registry()->seed($defaults))->toBe(0)
        ->and(CalendarCategory::where('slug', 'sport')->value('label'))->toBe('Bewegung');
});

it('has nothing to offer when categories are switched off', function () {
    config()->set('calendar.categories.enabled', false);
    category();

    expect(registry()->forUser(1))->toHaveCount(0)
        ->and(fn () => registry()->resolve('Sport', 1))->toThrow(CategoryNotAllowed::class);
});

it('exports categories with commas that separate, not commas that are escaped', function () {
    // Der Writer maskiert Kommas — bei CATEGORIES ist das Komma aber das
    // Trennzeichen. Ein naives implode() macht aus zwei Kategorien eine.
    BusinessKind::$categories = ['Gesundheit', 'Sport, Freizeit'];

    $event = CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Reha',
        'starts_at' => '2026-09-08 09:00:00',
        'ends_at' => '2026-09-08 10:00:00',
    ]);

    expect(app(IcsExporter::class)->event($event))
        ->toContain('CATEGORIES:Gesundheit,Sport\\, Freizeit');
});

it('leaves the categories line out when there is nothing to say', function () {
    $event = CalendarEvent::create([
        'kind' => 'business',
        'owner_id' => 1,
        'title' => 'Ohne',
        'starts_at' => '2026-09-08 09:00:00',
        'ends_at' => '2026-09-08 10:00:00',
    ]);

    expect(app(IcsExporter::class)->event($event))->not->toContain('CATEGORIES');
});

it('baut die CATEGORIES-Zeile auch fuer Anwendungen, die ihr ICS selbst zusammensetzen', function () {
    // Der Manager baut CalDAV-Antworten und den Abo-Feed selbst. Ohne diesen
    // einen Aufruf maskierte er das Trennzeichen dreimal von Hand — und beim
    // dritten Mal anders.
    $property = IcsCategories::property(['Gesundheit', ' Sport, Freizeit ', '', '  ']);

    expect($property)->not->toBeNull()
        ->and($property->getValue())->toBe('Gesundheit,Sport\\, Freizeit');
});

it('laesst die Zeile lieber weg, als sie leer zu schreiben', function () {
    expect(IcsCategories::property([]))->toBeNull()
        ->and(IcsCategories::property(['', '   ']))->toBeNull();
});

it('maskiert den Backslash zuerst, sonst frisst er die eigene Maskierung', function () {
    // Zuerst der Backslash: Sonst maskiert der Durchgang die Zeichen mit, die
    // er selbst gerade eingefuegt hat.
    expect(IcsCategories::property(['a\\b;c'])->getValue())->toBe('a\\\\b\;c')
        ->and(IcsCategories::property(["Zeile\nUmbruch"])->getValue())->toBe('Zeile\\nUmbruch');
});
