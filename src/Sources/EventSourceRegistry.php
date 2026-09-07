<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class EventSourceRegistry
{
    /** @var array<string, EventSource> */
    protected array $sources = [];

    public function register(EventSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /** @return array<string, EventSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /** @return array<string, EventSource> */
    public function available(): array
    {
        return array_filter($this->sources, fn (EventSource $source) => $source->isAvailable());
    }

    /**
     * Collects events from every available source.
     *
     * A source that fails does not take the calendar with it: the other events
     * are still shown and the failure is logged. A calendar that goes blank
     * because one remote system is slow is worse than one that is incomplete —
     * and the incompleteness is visible, the blank page is not explicable.
     *
     * @param  array<int, string>  $only  restrict to these source keys, empty = all
     * @return array<int, ExternalEvent>
     */
    public function collect(int $userId, CarbonInterface $from, CarbonInterface $to, array $only = []): array
    {
        $events = [];

        foreach ($this->available() as $key => $source) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            try {
                foreach ($source->events($userId, $from, $to) as $event) {
                    $events[] = $event;
                }
            } catch (\Throwable $e) {
                Log::warning('calendar.source_failed', [
                    'source' => $key,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        usort($events, fn (ExternalEvent $a, ExternalEvent $b) => $a->startsAt <=> $b->startsAt);

        return $events;
    }
}
