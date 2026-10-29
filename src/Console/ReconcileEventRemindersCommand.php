<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Peppermint\Calendar\Reminders\EventReminders;
use Peppermint\Calendar\Reminders\ReminderLead;
use Peppermint\Reminders\Models\Reminder;

class ReconcileEventRemindersCommand extends Command
{
    protected $signature = 'calendar:reconcile-reminders
                            {--dry-run : Alles rechnen und am Ende zurueckrollen — nichts bleibt stehen}';

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

        // Fehlt die Tabelle fuer den Vorlauf je Person, gilt fuer alle die
        // Vorgabe des Produkts. Das ist ein tragbarer Zustand, aber keiner,
        // den man raten soll: Wer die Maske benutzt hat und trotzdem die
        // Vorgabe bekommt, sucht den Fehler bei sich.
        if (! app(ReminderLead::class)->tabelleDa()) {
            $this->components->warn(
                'Die Tabelle fuer den Vorlauf je Person fehlt — es gilt fuer alle die Vorgabe. '
                .'Nachholen: php artisan vendor:publish --tag=calendar-reminder-leads && php artisan migrate'
            );
        }

        $probelauf = (bool) $this->option('dry-run');

        $bilanz = $probelauf ? $this->probelauf() : app(EventReminders::class)->reconcile();

        if ($probelauf) {
            $this->components->info('PROBELAUF — alles zurueckgerollt, nichts bleibt stehen.');
        }

        $this->components->twoColumnDetail('angelegt', (string) $bilanz['created']);
        $this->components->twoColumnDetail('verschoben', (string) $bilanz['moved']);
        $this->components->twoColumnDetail('weggenommen', (string) $bilanz['removed']);

        if ($probelauf) {
            // Die Zahl, auf die es beim Scharfschalten ankommt: Was waere
            // SOFORT faellig und ginge beim naechsten Zustell-Lauf an echte
            // Menschen heraus? „Angelegt" allein sagt das nicht — die meisten
            // Zeilen liegen in der Zukunft.
            $this->components->twoColumnDetail(
                '<fg=yellow>davon sofort faellig</>',
                (string) $bilanz['faellig_sofort']
            );
            $this->components->twoColumnDetail(
                '<fg=yellow>betroffene Menschen</>',
                (string) $bilanz['faellige_menschen']
            );
        }

        // Die uebergangenen gehoeren GENANNT, nicht verschwiegen. Ein Termin
        // ohne Beteiligte ist ein Befund: Die Erinnerung wuerde niemanden
        // erreichen, und ohne diese Zeile faellt das nie auf.
        //
        // Ein Termin, bei dem alle Beteiligten „keine Erinnerung" eingestellt
        // haben, steht hier NICHT — das ist eine Entscheidung und keine Luecke.
        if ($bilanz['skipped'] > 0) {
            $this->components->warn(
                $bilanz['skipped'].' Termin(e) ohne Beteiligte uebergangen — sie bekommen keine Erinnerung.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Derselbe Lauf, nur zurueckgerollt.
     *
     * ## Warum eine Transaktion und keine zweite Rechnung
     *
     * Weil ein Probelauf, der seinen eigenen Weg geht, kein Probelauf ist,
     * sondern eine Schaetzung mit Zahlen. Am 20.10.2026 ist genau das schon
     * passiert: Ein Probelauf pruefte eine von zwei Vorbedingungen, meldete
     * 10 — und der Echtlauf fand 30 Hindernisse. Die gefaehrlichste Sorte
     * Probelauf ist die, die gerade dafuer gebaut wird, dass man NICHT
     * nachsieht.
     *
     * Hier laeuft deshalb derselbe Code, mit denselben Abfragen, denselben
     * Einstellungen und denselben Grenzfaellen. Nur bleibt am Ende nichts
     * stehen.
     *
     * Dass dabei kurz geschrieben wird, ist kein Nebeneffekt, sondern die
     * Bedingung: Ohne die angelegten Zeilen liesse sich nicht zaehlen, welche
     * davon SOFORT faellig waeren — und das ist die Zahl, wegen der jemand
     * einen Probelauf verlangt.
     *
     * @return array{created: int, moved: int, removed: int, skipped: int, faellig_sofort: int, faellige_menschen: int}
     */
    private function probelauf(): array
    {
        DB::beginTransaction();

        try {
            $bilanz = app(EventReminders::class)->reconcile();

            $faellige = Reminder::query()
                ->fromSource(EventReminders::SOURCE_LEAD)
                ->active()
                ->due()
                ->undelivered();

            return $bilanz + [
                'faellig_sofort' => (clone $faellige)->count(),
                'faellige_menschen' => (clone $faellige)
                    ->distinct()
                    ->count(Reminder::column('user_id')),
            ];
        } finally {
            // Im `finally`, damit auch eine Ausnahme nichts stehen laesst.
            DB::rollBack();
        }
    }
}
