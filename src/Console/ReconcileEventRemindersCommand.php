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
        $probelauf = (bool) $this->option('dry-run');
        $eingeschaltet = (bool) config('calendar.reminders.enabled', false);

        if (! $eingeschaltet && ! $probelauf) {
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

        $bilanz = $probelauf ? $this->probelauf() : app(EventReminders::class)->reconcile();

        if ($probelauf) {
            $this->components->info('PROBELAUF — alles zurueckgerollt, nichts bleibt stehen.');
        }

        if ($probelauf && ! $eingeschaltet) {
            $this->components->warn(
                'Der Schalter steht auf AUS — gemessen wurde, was beim Einschalten passieren WUERDE.'
            );
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
     * ## Warum der Schalter hier umgelegt wird (29.10.2026)
     *
     * Weil er sonst genau die Frage verbietet, fuer die es den Probelauf gibt.
     * Bis heute kehrte {@see EventReminders::reconcile()} bei
     * `enabled = false` sofort mit Nullen um — und das ist der Zustand JEDES
     * Produkts, das noch nicht scharf geschaltet ist. Der Probelauf meldete
     * dort „0 angelegt, 0 sofort faellig", und das las sich wie eine
     * Entwarnung. Es war keine: Es war die Antwort auf eine Frage, die nie
     * gestellt wurde.
     *
     * Gefordert ist „vorher zaehlen, was beim ersten Lauf an echte Menschen
     * rausginge". Diese Zahl gab es vorher erst NACH dem Einschalten — also
     * nachdem das Risiko eingegangen war, gegen das sie schuetzen soll.
     *
     * Umgelegt wird nur im Speicher dieses einen Prozesses: `config()` schreibt
     * keine Datei, und der Prozess endet mit dem Befehl. Die Transaktion rollt
     * davon unabhaengig zurueck.
     *
     * @return array{created: int, moved: int, removed: int, skipped: int, faellig_sofort: int, faellige_menschen: int}
     */
    private function probelauf(): array
    {
        DB::beginTransaction();

        // Siehe Kopf: ohne das misst ein Probelauf bei ausgeschaltetem
        // Schalter nichts und meldet trotzdem eine Zahl.
        $vorher = config('calendar.reminders.enabled', false);
        config(['calendar.reminders.enabled' => true]);

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

            // Und der Schalter zurueck. Dass der Prozess gleich endet, ist
            // kein Argument: Unter Octane bleibt die Anwendung stehen, und ein
            // Befehl, der aus einem Auftrag heraus laeuft, haette den Riegel
            // dann fuer alles danach geoeffnet.
            config(['calendar.reminders.enabled' => $vorher]);
        }
    }
}
