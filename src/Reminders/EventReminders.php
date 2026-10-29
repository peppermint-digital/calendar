<?php

namespace Peppermint\Calendar\Reminders;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Reminders\Abgleich\Abgleich;
use Peppermint\Reminders\Abgleich\AnlassMitEmpfaengern;
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
 *
 * ## Wer erinnert wird: der Eigner UND die Teilnehmer (Stand 29.10.2026)
 *
 * Hier stand bis zum 29.10.2026 der Eigner, und nur er. Die Begruendung war:
 *
 * > „Alle Teilnehmer ausser dem Eigner" ist eine Frage an produkteigene
 * > Beziehungen — der Kern nimmt eine Antwort an, er ermittelt sie nicht.
 *
 * Die andere Seite dieser Rechnung hat Bug #1080 im Peppermint Manager
 * gezeigt. Chris bekam keine Terminerinnerungen, und der abgeschaltete
 * Schalter war nur der erste Grund: Bei seinen Terminen ist er TEILNEHMER.
 * Auch eingeschaltet haette er nie etwas bekommen — und der Lauf haette dabei
 * „angelegt" gemeldet, fuer jemand anderen. Ein Termin ohne Erinnerung fuer
 * seine Teilnehmer ist kein Grenzfall, sondern der Normalfall einer
 * Besprechung.
 *
 * Die alte Begruendung war ausserdem an der falschen Stelle gemessen:
 * `calendar_event_attendees` ist eine KERNTABELLE dieses Pakets, mit einer
 * Beziehung dieses Pakets. Teilnehmer sind kein Produktwissen, sie stehen hier.
 *
 * Was die alte Entscheidung schuetzen wollte, hat weiter einen Weg: Der
 * `$recipients`-Haken nimmt die Antwort des Produkts, wenn es eine hat, und
 * dann ermittelt diese Klasse nichts.
 */
class EventReminders implements AnlassMitEmpfaengern
{
    /** The one source this rule owns. Dedup hangs off it. */
    public const SOURCE_LEAD = 'event:lead';

    /**
     * Termine, an denen GAR NIEMAND haengt — kein Eigner, kein interner
     * Teilnehmer.
     *
     * Gesammelt waehrend des Laufs, weil nur diese Klasse den Unterschied
     * kennt: Ein Termin, bei dem alle Beteiligten „keine Erinnerung" gewaehlt
     * haben, sieht fuer den Abgleich genauso aus wie einer ohne Beteiligte —
     * der erste ist eine Entscheidung, der zweite ein Befund.
     *
     * @var array<int, mixed>
     */
    private array $ohneBeteiligte = [];

    /**
     * @param  Closure(CalendarEvent): (int|string|array<int, int|string>|null)|null  $recipients
     *         Who to remind. Without it: the owner and every internal
     *         attendee. A product that knows better hands in its own answer —
     *         a single id or null is still accepted, which is the form this
     *         hook had while there was only ever one recipient.
     */
    public function __construct(
        private ?Closure $recipients = null,
        private ?ReminderLead $vorlauf = null,
    ) {}

    /**
     * Bringt die erzeugten Erinnerungen in Einklang mit den Terminen.
     *
     * ## Seit 26.10.2026 nur noch die Haelfte
     *
     * Das Anlegen, Verschieben, Abgehaktes-in-Ruhe-Lassen, Wegnehmen und
     * Zaehlen macht {@see Abgleich} im Erinnerungs-Paket. Diese Klasse
     * beantwortet nur noch, WELCHE Termine eine Erinnerung verdienen, WEN sie
     * erreicht und WANN.
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

        $this->ohneBeteiligte = [];

        $bilanz = (new Abgleich($this))->fahren();

        return [
            'created' => $bilanz['angelegt'],
            'moved' => $bilanz['verschoben'],
            'removed' => $bilanz['weggenommen'],
            'skipped' => count($this->ohneBeteiligte),
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
        //
        // Die Teilnehmer kommen MIT. Ohne das waere es eine Abfrage je Termin,
        // und zwei Produkte fahren `preventLazyLoading` — dort waere es nicht
        // langsam, sondern ein Abbruch mitten im Lauf.
        return $klasse::query()
            ->with('attendees')
            ->where($klasse::column('starts_at'), '>', now());
    }

    public function wortlaut(Model $gegenstand, string $quelle): string
    {
        return (string) $gegenstand->field('title');
    }

    /**
     * Wer erinnert wird, und wann — je Person ein eigener Zeitpunkt.
     *
     * Eine Person, die „keine Erinnerung" eingestellt hat, FEHLT hier; der
     * Abgleich nimmt ihr eine vorhandene Zeile dann weg. Damit braucht das
     * Abstellen kein zweites Feld.
     *
     * @return array<int|string, array<string, DateTimeInterface>>
     */
    public function empfaengerMomente(Model $gegenstand): array
    {
        $beteiligte = $this->beteiligte($gegenstand);

        if ($beteiligte === []) {
            // Ein Befund, kein Normalfall — und nur hier unterscheidbar von
            // „alle haben es abgestellt". Siehe {@see $ohneBeteiligte}.
            $this->ohneBeteiligte[] = $gegenstand->getKey();

            return [];
        }

        $vorlauf = $this->vorlauf();
        $momente = [];

        foreach ($beteiligte as $person) {
            $minuten = $vorlauf->minutenFuer($person);

            if ($minuten === null) {
                continue;
            }

            $momente[$person] = [self::SOURCE_LEAD => $this->moment($gegenstand, $minuten)];
        }

        return $momente;
    }

    /**
     * Der Eigner und die internen Teilnehmer — ohne Doppelte.
     *
     * ## Warum ein abgesagter Teilnehmer NICHT dabei ist
     *
     * Wer abgesagt hat, hat gesagt, dass er nicht kommt. Ihn zu erinnern waere
     * die Art von Meldung, die Menschen dazu bringt, alle Meldungen
     * abzuschalten. `pending` und `tentative` bleiben drin: Dort ist die Frage
     * offen, und eine Vorwarnung ist genau dann nuetzlich.
     *
     * ## Warum externe Teilnehmer nicht dabei sind
     *
     * Sie haben kein Konto in diesem System (`user_id` ist null) — es gibt
     * niemanden, dem eine Glocke gehoert. Ihr Weg ist die Einladung per Mail,
     * und das ist eine andere Sache an einer anderen Stelle.
     *
     * @return array<int, int|string>
     */
    private function beteiligte(Model $gegenstand): array
    {
        if ($this->recipients !== null) {
            $antwort = ($this->recipients)($gegenstand);

            if ($antwort === null) {
                return [];
            }

            return array_values(array_unique(array_filter(
                (array) $antwort,
                fn ($wert): bool => $wert !== null && $wert !== '',
            )));
        }

        $leute = [];

        $eigner = $gegenstand->field('owner_id');

        if ($eigner !== null) {
            $leute[] = (int) $eigner;
        }

        foreach ($gegenstand->attendees as $teilnehmer) {
            if ($teilnehmer->user_id === null || $teilnehmer->status === 'declined') {
                continue;
            }

            $leute[] = (int) $teilnehmer->user_id;
        }

        return array_values(array_unique($leute));
    }

    /**
     * Der Zeitpunkt, an dem erinnert wird.
     *
     * Die Rechnung selbst liegt in {@see LeadMoment} — sie wird seit dem
     * 29.10.2026 von einem zweiten Anlass gebraucht (abonnierte fremde
     * Kalender im Manager, Bug #1081), und dort stand sie prompt ein zweites
     * Mal. Mit ihr ist auch die Zeitzonen-Falle an EINER Stelle behoben; die
     * Begruendung steht im Kopf jener Klasse.
     *
     * Der Vorlauf gilt bei einem Ganztagstermin fuer niemanden: Es gibt keine
     * Uhrzeit, von der er abgezogen werden koennte. Was je Person bleibt, ist
     * die Frage, OB erinnert wird.
     */
    private function moment(Model $termin, int $minuten): CarbonImmutable
    {
        return LeadMoment::fuer(
            $termin->field('starts_at'),
            (bool) $termin->field('all_day'),
            $minuten,
        );
    }

    private function vorlauf(): ReminderLead
    {
        return $this->vorlauf ??= app(ReminderLead::class);
    }
}
