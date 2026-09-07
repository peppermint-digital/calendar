<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;

beforeEach(function () {
    DemoSource::$available = true;
    DemoSource::$throws = false;

    config()->set('calendar.sources', [DemoSource::class]);
    app()->forgetInstance(EventSourceRegistry::class);
});

function collectSources(array $only = []): array
{
    return app(EventSourceRegistry::class)->collect(
        1,
        CarbonImmutable::parse('2026-09-07'),
        CarbonImmutable::parse('2026-09-13'),
        $only,
    );
}

it('shows events that live in another system', function () {
    $events = collectSources();

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('Meeting elsewhere');
});

it('prefixes ids with the source so they cannot collide with local ones', function () {
    expect(collectSources()[0]->toArray()['id'])->toBe('demo:42')
        ->and(collectSources()[0]->toArray()['external'])->toBeTrue();
});

it('skips a source that is not configured on this installation', function () {
    DemoSource::$available = false;

    expect(collectSources())->toBe([]);
});

it('keeps the calendar working when a remote system fails', function () {
    // A calendar that goes blank because one remote system is slow is worse
    // than one that is incomplete — the gap is visible, the blank page is not
    // explicable.
    DemoSource::$throws = true;

    expect(collectSources())->toBe([]);
});

it('can be restricted to certain sources', function () {
    expect(collectSources(['demo']))->toHaveCount(1)
        ->and(collectSources(['something-else']))->toBe([]);
});

it('lists the sources it knows', function () {
    expect(array_keys(app(EventSourceRegistry::class)->all()))->toBe(['demo']);
});
