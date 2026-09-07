<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Models\CalendarEvent;

class PurgeTrashedEventsCommand extends Command
{
    protected $signature = 'calendar:purge-trash
                            {--kind= : Nur diese Terminart aufräumen}
                            {--dry-run : Nur zählen, nichts löschen}';

    protected $description = 'Entfernt Termine endgültig, die länger als die Aufbewahrungsfrist ihrer Art im Papierkorb liegen.';

    public function handle(EventKindRegistry $registry): int
    {
        $only = $this->option('kind');
        $dry = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($registry->all() as $key => $kind) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            if (! $kind->usesTrash()) {
                continue;
            }

            $days = $kind->trashRetentionDays();

            if ($days === null) {
                $this->line("· {$key}: unbegrenzte Aufbewahrung, übersprungen");

                continue;
            }

            $cutoff = Carbon::now()->subDays($days);

            $query = CalendarEvent::onlyTrashed()
                ->where('kind', $key)
                ->where('deleted_at', '<', $cutoff);

            $count = (clone $query)->count();
            $total += $count;

            if ($count === 0) {
                $this->line("· {$key}: nichts fällig (Frist {$days} Tage)");

                continue;
            }

            if ($dry) {
                $this->line("· {$key}: {$count} fällig (Frist {$days} Tage) — Probelauf, nichts gelöscht");

                continue;
            }

            $query->get()->each(fn (CalendarEvent $event) => $event->forceDelete());

            $this->line("· {$key}: {$count} endgültig entfernt (Frist {$days} Tage)");
        }

        $this->info($dry
            ? "Probelauf: {$total} Termine wären entfernt worden."
            : "{$total} Termine endgültig entfernt.");

        return self::SUCCESS;
    }
}
