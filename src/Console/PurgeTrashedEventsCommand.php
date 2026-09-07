<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Models\CalendarEvent;

class PurgeTrashedEventsCommand extends Command
{
    protected $signature = 'calendar:purge-trash
                            {--kind= : Only purge this event kind}
                            {--dry-run : Count only, delete nothing}';

    protected $description = 'Permanently removes events that have been in the trash bin longer than their kind allows.';

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
                $this->line("· {$key}: kept forever, skipped");

                continue;
            }

            $cutoff = Carbon::now()->subDays($days);

            $query = CalendarEvent::onlyTrashed()
                ->where(CalendarEvent::column('kind'), $key)
                ->where('deleted_at', '<', $cutoff);

            $count = (clone $query)->count();
            $total += $count;

            if ($count === 0) {
                $this->line("· {$key}: nothing due (retention {$days} days)");

                continue;
            }

            if ($dry) {
                $this->line("· {$key}: {$count} due (retention {$days} days) — dry run, nothing deleted");

                continue;
            }

            $query->get()->each(fn (CalendarEvent $event) => $event->forceDelete());

            $this->line("· {$key}: {$count} permanently removed (retention {$days} days)");
        }

        $this->info($dry
            ? "Dry run: {$total} events would have been removed."
            : "{$total} events permanently removed.");

        return self::SUCCESS;
    }
}
