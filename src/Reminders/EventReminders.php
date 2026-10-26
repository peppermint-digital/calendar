<?php

namespace Peppermint\Calendar\Reminders;

use Carbon\CarbonImmutable;
use Closure;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Reminders\Models\Reminder;
use RuntimeException;

/**
 * Turns a start time into reminders somebody actually receives.
 *
 * ## Why this generates rows instead of sending anything
 *
 * It would have been shorter to notify directly. It would also have been a
 * SECOND delivery path next to the reminders people set by hand — with its own
 * repeat-lock, its own idea of "already told you", its own drift. That is the
 * shape of the bug that produced `peppermint/reminders` in the first place.
 *
 * So the start time produces an ordinary {@see Reminder}. Delivery, the
 * once-only lock, the bell, snoozing, dismissal and history are the ones that
 * already exist — and the event reminder shows up in the same list as the
 * hand-set ones.
 *
 * ## Why the row is absolute and not "15 minutes before"
 *
 * Decided on 17.09.2026 (AI Brain #5808, idea #437) and deliberately not
 * re-opened here: the row stays absolute, a rule owns it.
 *
 * > One kind of row, one delivery path. With both kinds every "what is due?"
 * > query would have to know two cases — the absolute one in the column, the
 * > relative one computed at read time. That is exactly the branch somebody
 * > later forgets at one call site.
 *
 * And the rule can do more than a formula: "event moved", "event deleted",
 * "already started" are not expressible as a minute offset.
 *
 * ## What "reconcile" means
 *
 * Not "create once and forget". An event that moves takes its reminder with
 * it, an event that goes away takes it away, and an event that has started
 * stops nagging.
 *
 * ## The all-day case
 *
 * An all-day event starts at midnight, so "15 minutes before" lands at 23:45
 * the night before — correct arithmetic, useless reminder. It gets the morning
 * of the day instead, in LOCAL time: the column stores UTC, and without the
 * zone "08:00" arrives two hours late in summer.
 */
class EventReminders
{
    /** The one source this rule owns. Dedup hangs off it. */
    public const SOURCE_LEAD = 'event:lead';

    /**
     * @param  Closure(CalendarEvent): (int|null)|null  $recipient  Who to
     *         remind. Defaults to the event's owner; a product that plans for
     *         other people hands in its own answer.
     */
    public function __construct(private ?Closure $recipient = null) {}

    /**
     * @return array<string, int>
     */
    public function reconcile(): array
    {
        if (! class_exists(Reminder::class)) {
            // Lieber abbrechen als halb arbeiten: Ohne das Erinnerungs-Paket
            // gibt es nichts, wohin die Zeilen koennten, und ein Lauf, der
            // „0 angelegt" meldet, liest sich wie „es gab nichts zu tun".
            throw new RuntimeException(
                'Termin-Erinnerungen brauchen peppermint/reminders. '
                .'Paket installieren oder calendar.reminders.enabled abschalten.'
            );
        }

        $bilanz = ['created' => 0, 'moved' => 0, 'removed' => 0, 'skipped' => 0];

        if (! config('calendar.reminders.enabled', false)) {
            return $bilanz;
        }

        $klasse = $this->eventKlasse();

        // Die Erinnerungen werden je Haeppchen nachgeladen, NICHT ueber eine
        // Beziehung am Termin-Modell. Zwei Gruende, und beide zaehlen:
        //
        // 1. `peppermint/reminders` ist ein `suggest`. Eine Beziehung, deren
        //    Rumpf eine fehlende Klasse nennt, bricht beim Aufruf — und sie
        //    stuende dann in einem Modell, das jedes Produkt erbt.
        // 2. Der Name `reminders` ist in Produkten schon vergeben. Das
        //    Aufgaben-Paket hat genau daran einmal einen halben Tag verloren:
        //    Eine Beziehung mit festem Rueckgabetyp laesst sich nicht
        //    ueberschreiben, und PHP bricht beim LADEN der Klasse ab.
        $klasse::query()
            ->where($klasse::column('starts_at'), '>', now())
            ->chunkById(200, function ($termine) use (&$bilanz, $klasse): void {
                $vorhandene = Reminder::query()
                    ->where('source', self::SOURCE_LEAD)
                    ->where('subject_type', (new $klasse)->getMorphClass())
                    ->whereIn('subject_id', $termine->modelKeys())
                    ->get()
                    ->keyBy('subject_id');

                foreach ($termine as $termin) {
                    $empfaenger = $this->empfaenger($termin);

                    if ($empfaenger === null) {
                        // Gezaehlt, nicht still uebergangen: Ein Termin ohne
                        // Empfaenger ist ein Befund, keine Nebensache.
                        $bilanz['skipped']++;

                        continue;
                    }

                    $this->abgleichen(
                        $termin,
                        $vorhandene->get($termin->getKey()),
                        $this->moment($termin),
                        (int) $empfaenger,
                        $bilanz
                    );
                }
            });

        $this->entferneVerwaiste($bilanz);

        return $bilanz;
    }

    /**
     * Der Zeitpunkt, an dem erinnert wird.
     */
    private function moment(CalendarEvent $termin): CarbonImmutable
    {
        $start = CarbonImmutable::parse($termin->field('starts_at'));

        if ((bool) $termin->field('all_day')) {
            $zone = (string) config('calendar.reminders.timezone', 'Europe/Berlin');
            $stunde = (int) config('calendar.reminders.all_day_hour', 8);

            return $start->setTimezone($zone)->startOfDay()->addHours($stunde)->utc();
        }

        return $start->subMinutes((int) config('calendar.reminders.lead_minutes', 15));
    }

    private function abgleichen(CalendarEvent $termin, ?Reminder $vorhanden, CarbonImmutable $zeitpunkt, int $empfaenger, array &$bilanz): void
    {
        if ($vorhanden === null) {
            Reminder::query()->create([
                'subject_type' => $termin->getMorphClass(),
                'subject_id' => $termin->getKey(),
                'user_id' => $empfaenger,
                'remind_at' => $zeitpunkt,
                'source' => self::SOURCE_LEAD,
                'title' => (string) $termin->field('title'),
            ]);

            $bilanz['created']++;

            return;
        }

        // Abgehaktes bleibt, wie es ist. Es ist der Nachweis, dass jemand
        // erinnert wurde und gehandelt hat — daran nachtraeglich zu drehen
        // hiesse, die Geschichte umzuschreiben.
        if ($vorhanden->is_dismissed) {
            return;
        }

        $verschoben = ! $vorhanden->remind_at->equalTo($zeitpunkt)
            || (int) $vorhanden->user_id !== $empfaenger;

        if (! $verschoben) {
            return;
        }

        $vorhanden->forceFill([
            'remind_at' => $zeitpunkt,
            'user_id' => $empfaenger,
            'title' => (string) $termin->field('title'),
            // Der Termin ist woanders: Was zum alten Zeitpunkt herausging,
            // gilt nicht mehr. Ohne das Zuruecksetzen erreicht die
            // verschobene Erinnerung niemanden.
            'delivered_at' => null,
        ])->save();

        $bilanz['moved']++;
    }

    /**
     * Zeilen wegnehmen, deren Anlass es nicht mehr gibt.
     *
     * Drei Faelle, und alle drei sind echt: Der Termin ist geloescht (das
     * Paket fuehrt SoftDeletes, der Fremdschluessel fehlt absichtlich und
     * kaskadiert deshalb nicht), er hat begonnen, oder er hat keinen
     * Empfaenger mehr.
     *
     * Abgehaktes bleibt auch hier stehen — es ist Geschichte, nicht Rueckstand.
     */
    private function entferneVerwaiste(array &$bilanz): void
    {
        $klasse = $this->eventKlasse();

        $offen = $klasse::query()
            ->where($klasse::column('starts_at'), '>', now())
            ->pluck((new $klasse)->getKeyName());

        $bilanz['removed'] = Reminder::query()
            ->where('source', self::SOURCE_LEAD)
            ->where('subject_type', (new $klasse)->getMorphClass())
            ->where('is_dismissed', false)
            ->whereNotIn('subject_id', $offen)
            ->delete();
    }

    private function empfaenger(CalendarEvent $termin): ?int
    {
        if ($this->recipient !== null) {
            $wert = ($this->recipient)($termin);

            return $wert === null ? null : (int) $wert;
        }

        $eigner = $termin->field('owner_id');

        return $eigner === null ? null : (int) $eigner;
    }

    /**
     * Die Termin-Klasse des PRODUKTS, nicht die des Pakets.
     *
     * `getMorphClass()` darauf liefert den Typ, der in `subject_type` landet.
     * Mit der Paket-Klasse stuende dort ein Modell, das kein Produkt
     * instanziiert — und die Suche nach den Erinnerungen eines Termins traefe
     * nie. Lautlos.
     *
     * @return class-string<CalendarEvent>
     */
    private function eventKlasse(): string
    {
        return (string) config('calendar.event_model', CalendarEvent::class);
    }
}
