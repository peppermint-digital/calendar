<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Sources\RemoteCalendar;
use Peppermint\Calendar\Sources\RemoteResult;

/**
 * Ein fremdes System, das es nicht gibt.
 *
 * Steht fuer die Gegenstelle, ohne einen Weg dorthin zu brauchen — genau die
 * Trennung, fuer die der Vertrag da ist. Was hier eingestellt wird, ist das,
 * was eine echte Anwendung aus ihrem Faehigkeitsregister, ihrer
 * Rechteverwaltung und ihrem Gateway beantworten wuerde.
 */
class FakeRemoteCalendar implements RemoteCalendar
{
    /** @var array<int, string> */
    public array $faehigkeiten = [
        'calendar.events.list',
        'calendar.events.show',
        'calendar.events.create',
        'calendar.events.update',
        'calendar.events.delete',
        'calendar.kinds.list',
    ];

    public bool $darfSchreiben = true;

    public ?string $kennung = 'person@beispiel.de';

    public ?string $wurzel = 'https://vw.beispiel.de';

    /** @var array<string, RemoteResult> */
    public array $antworten = [];

    /** @var array<int, array{capability: string, arguments: array<string, mixed>}> */
    public array $aufrufe = [];

    public function key(): string
    {
        return 'verwaltung';
    }

    public function label(): string
    {
        return 'Verwaltung';
    }

    public function offers(string $capability): bool
    {
        return in_array($capability, $this->faehigkeiten, true);
    }

    public function mayWrite(int $userId): bool
    {
        return $this->darfSchreiben;
    }

    public function identify(int $userId): ?string
    {
        return $this->kennung;
    }

    public function baseUrl(): ?string
    {
        return $this->wurzel;
    }

    public function call(string $capability, array $arguments = []): RemoteResult
    {
        $this->aufrufe[] = ['capability' => $capability, 'arguments' => $arguments];

        return $this->antworten[$capability] ?? RemoteResult::failed('Nichts eingestellt fuer '.$capability, 'not_offered');
    }

    /** Der letzte Aufruf dieses Vorgangs — oder null. */
    public function letzterAufruf(string $capability): ?array
    {
        foreach (array_reverse($this->aufrufe) as $aufruf) {
            if ($aufruf['capability'] === $capability) {
                return $aufruf['arguments'];
            }
        }

        return null;
    }
}
