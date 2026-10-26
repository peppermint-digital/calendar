<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Peppermint\Calendar\Reminders\EventReminders;

class ReconcileEventRemindersCommand extends Command
{
    protected $signature = 'calendar:reconcile-reminders';

    protected $description = 'Turns upcoming event start times into reminder rows, and moves or removes them when the events change.';

    public function handle(): int
    {
        if (! config('calendar.reminders.enabled', false)) {
            // Nicht als Fehler: Abgeschaltet ist die Vorgabe, und ein Lauf,
            // der deswegen abbricht, laesst einen Zeitplan rot blinken fuer
            // eine Entscheidung, die jemand bewusst getroffen hat.
            $this->components->info('Termin-Erinnerungen sind abgeschaltet (calendar.reminders.enabled).');

            return self::SUCCESS;
        }

        $bilanz = app(EventReminders::class)->reconcile();

        $this->components->twoColumnDetail('angelegt', (string) $bilanz['created']);
        $this->components->twoColumnDetail('verschoben', (string) $bilanz['moved']);
        $this->components->twoColumnDetail('weggenommen', (string) $bilanz['removed']);

        // Die uebergangenen gehoeren GENANNT, nicht verschwiegen. Ein Termin
        // ohne Empfaenger ist ein Befund: Die Erinnerung wuerde niemanden
        // erreichen, und ohne diese Zeile faellt das nie auf.
        if ($bilanz['skipped'] > 0) {
            $this->components->warn(
                $bilanz['skipped'].' Termin(e) ohne Empfaenger uebergangen — sie bekommen keine Erinnerung.'
            );
        }

        return self::SUCCESS;
    }
}
