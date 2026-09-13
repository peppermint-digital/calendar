<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Tests\Fixtures\DemoSource;

beforeEach(function () {
    DemoSource::$available = true;
    DemoSource::$throws = false;
    DemoSource::$extra = [];

    config()->set('calendar.sources', [DemoSource::class]);
    app()->forgetInstance(EventSourceRegistry::class);
});

function collectSources(array $only = []): array
{
    // Bewusst mit einfachem DateTimeImmutable: Das Paket darf seine Nutzer
    // nicht zu Carbon zwingen — genau daran ist der erste Live-Aufruf
    // gescheitert.
    return app(EventSourceRegistry::class)->collect(
        1,
        new DateTimeImmutable('2026-09-07'),
        new DateTimeImmutable('2026-09-13'),
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

it('carries fields only the other system knows', function () {
    // Der Grund fuer diesen Kanal: Ein Produkt braucht ein Feld mehr, und die
    // billigste Stelle sieht aus wie eine neue Spalte in der geteilten Mitte.
    // Eine Spalte laege danach in jedem anderen Produkt auch.
    DemoSource::$extra = ['project' => 'Roncalli', 'billable' => false];

    expect(collectSources()[0]->toArray()['extra'])
        ->toBe(['project' => 'Roncalli', 'billable' => false]);
});

it('leaves the extra key out when there is nothing to say', function () {
    // Ein Schluessel, der immer da, aber meist leer ist, erzieht Leser dazu,
    // ihn zu uebersehen.
    expect(collectSources()[0]->toArray())->not->toHaveKey('extra');
});

it('refuses extra fields that would overwrite what the core says', function () {
    // Ohne diesen Riegel koennte ein fremdes System die Id oder die Zeiten
    // seines eigenen Termins ueberschreiben, indem es ein Feld geschickt
    // benennt — und der Termin liesse sich einem schreibenden Aufruf
    // unterschieben.
    DemoSource::$extra = [
        'id' => 'gekapert',
        'starts_at' => '1999-01-01',
        'external' => false,
        'project' => 'bleibt',
    ];

    $payload = collectSources()[0]->toArray();

    expect($payload['id'])->toBe('demo:42')
        ->and($payload['external'])->toBeTrue()
        ->and($payload['starts_at'])->toStartWith('2026-09-08')
        ->and($payload['extra'])->toBe(['project' => 'bleibt']);
});
