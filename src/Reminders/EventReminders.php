<?php

namespace Peppermint\Calendar\Reminders;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Reminders\Abgleich\Abgleich;
use Peppermint\Reminders\Abgleich\ErinnerungsAnlass;
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
class EventReminders implements ErinnerungsAnlass
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
     * Bringt die erzeugten Erinnerungen in Einklang mit den Terminen.
     *
     * ## Seit 26.10.2026 nur noch die Haelfte
     *
     * Das Anlegen, Verschieben, Abgehaktes-in-Ruhe-Lassen, Wegnehmen und
     * Zaehlen macht {@see Abgleich} im Erinnerungs-Paket. Diese Klasse
     * beantwortet nur noch, WELCHE Termine eine Erinnerung verdienen und WANN.
     *
     * Grund: Das Aufgaben-Paket hatte dieselben Bauteile ein zweites Mal —
     * 238 gegen 247 Zeilen, verschieden war genau `momente()`. Pruefrage 3
     * des Paketvertrags.
     *
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

        if (! config('calendar.reminders.enabled', false)) {
            return ['created' => 0, 'moved' => 0, 'removed' => 0, 'skipped' => 0];
        }

        $bilanz = (new Abgleich($this))->fahren();

        return [
            'created' => $bilanz['angelegt'],
            'moved' => $bilanz['verschoben'],
            'removed' => $bilanz['weggenommen'],
            'skipped' => count($bilanz['ohne_empfaenger']),
        ];
    }

    /** @return array<int, string> */
    public function quellen(): array
    {
        return [self::SOURCE_LEAD];
    }

    /**
     * Die Termin-Klasse des PRODUKTS, nicht die des Pakets.
     *
     * Daran haengt der Morph-Typ in `subject_type`. Mit der Paket-Klasse
     * stuende dort ein Modell, das kein Produkt instanziiert — und die Suche
     * nach den Erinnerungen eines Termins traefe nie. Lautlos.
     *
     * @return class-string<CalendarEvent>
     */
    public function gegenstandsKlasse(): string
    {
        return (string) config('calendar.event_model', CalendarEvent::class);
    }

    public function gegenstaende()
    {
        $klasse = $this->gegenstandsKlasse();

        // Nur was noch kommt. Ein Termin, der begonnen hat, braucht keine
        // Vorwarnung mehr — und seine Zeile raeumt der Abgleich weg, weil er
        // aus dieser Menge gefallen ist. Geloeschte faellt ebenfalls heraus:
        // Das Paket fuehrt SoftDeletes, der polymorphe Verweis traegt keinen
        // Fremdschluessel und kaskadiert deshalb nicht.
        return $klasse::query()->where($klasse::column('starts_at'), '>', now());
    }

    /**
     * @return array<string, DateTimeInterface>
     */
    public function momente(Model $gegenstand): array
    {
        return [self::SOURCE_LEAD => $this->moment($gegenstand)];
    }

    public function wortlaut(Model $gegenstand, string $quelle): string
    {
        return (string) $gegenstand->field('title');
    }

    public function empfaenger(Model $gegenstand): int|string|null
    {
        if ($this->recipient !== null) {
            $wert = ($this->recipient)($gegenstand);

            return $wert === null ? null : (int) $wert;
        }

        $eigner = $gegenstand->field('owner_id');

        return $eigner === null ? null : (int) $eigner;
    }

    /**
     * Der Zeitpunkt, an dem erinnert wird.
     */
    private function moment(Model $termin): CarbonImmutable
    {
        $start = CarbonImmutable::parse($termin->field('starts_at'));

        if ((bool) $termin->field('all_day')) {
            $zone = (string) config('calendar.reminders.timezone', 'Europe/Berlin');
            $stunde = (int) config('calendar.reminders.all_day_hour', 8);

            return $start->setTimezone($zone)->startOfDay()->addHours($stunde)->utc();
        }

        return $start->subMinutes((int) config('calendar.reminders.lead_minutes', 15));
    }

}
