<?php

namespace Peppermint\Calendar\TimeBlocking;

/**
 * What the board shows when it is opened.
 *
 * These are deliberately different from a general calendar's defaults. A
 * calendar is for overview, so it shows everything; a planning board is for
 * arranging one person's day, and everything else is noise there. Carrying a
 * calendar's defaults into a board — or the other way round — produces a view
 * that is not broken, merely wrong, which is the harder kind to notice.
 */
class BoardPreferences
{
    public function __construct(
        public readonly bool $onlyOwnBlocks = true,
        public readonly bool $onlyOwnItems = true,
        public readonly bool $showWeekends = false,
        public readonly bool $showAllDayEvents = false,
        /** @var array<int, string> */
        public readonly array $kinds = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $defaults = new self;

        return new self(
            onlyOwnBlocks: (bool) ($data['onlyOwnBlocks'] ?? $defaults->onlyOwnBlocks),
            onlyOwnItems: (bool) ($data['onlyOwnItems'] ?? $defaults->onlyOwnItems),
            showWeekends: (bool) ($data['showWeekends'] ?? $defaults->showWeekends),
            showAllDayEvents: (bool) ($data['showAllDayEvents'] ?? $defaults->showAllDayEvents),
            kinds: array_values((array) ($data['kinds'] ?? $defaults->kinds)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'onlyOwnBlocks' => $this->onlyOwnBlocks,
            'onlyOwnItems' => $this->onlyOwnItems,
            'showWeekends' => $this->showWeekends,
            'showAllDayEvents' => $this->showAllDayEvents,
            'kinds' => $this->kinds,
        ];
    }
}
