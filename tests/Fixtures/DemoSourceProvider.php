<?php

namespace Peppermint\Calendar\Tests\Fixtures;

use Peppermint\Calendar\Sources\EventSourceProvider;

/** Steht fuer Quellen, die erst zur Laufzeit feststehen. */
class DemoSourceProvider implements EventSourceProvider
{
    /** @var array<int, \Peppermint\Calendar\Sources\EventSource> */
    public static array $liefert = [];

    public static int $aufrufe = 0;

    public function sources(): array
    {
        self::$aufrufe++;

        return self::$liefert;
    }
}
