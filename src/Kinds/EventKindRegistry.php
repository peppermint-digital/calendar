<?php

namespace Peppermint\Calendar\Kinds;

use Peppermint\Calendar\Exceptions\UnknownEventKind;

class EventKindRegistry
{
    /** @var array<string, EventKind> */
    protected array $kinds = [];

    public function register(EventKind $kind): void
    {
        $this->kinds[$kind->key()] = $kind;
    }

    public function has(string $key): bool
    {
        return isset($this->kinds[$key]);
    }

    /**
     * @throws UnknownEventKind
     */
    public function get(string $key): EventKind
    {
        return $this->kinds[$key] ?? throw UnknownEventKind::for($key, array_keys($this->kinds));
    }

    /** @return array<string, EventKind> */
    public function all(): array
    {
        return $this->kinds;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->kinds);
    }
}
