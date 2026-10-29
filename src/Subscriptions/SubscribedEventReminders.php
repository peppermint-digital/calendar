<?php

namespace Peppermint\Calendar\Subscriptions;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Reminders\LeadMoment;
use Peppermint\Calendar\Reminders\ReminderLead;
use Peppermint\Calendar\Subscriptions\Models\SubscribedEvent;
use Peppermint\Reminders\Abgleich\ErinnerungsAnlass;

/**
 * Erinnerungen fuer Termine aus abonnierten fremden Kalendern (Bug #1081).
 *
 * ## Wer erinnert wird
 *
 * Der Mensch, der den Kalender abonniert hat. Genau einer — deshalb der
 * einfache {@see ErinnerungsAnlass} und nicht die Mehr-Empfaenger-Form, die
 * der eigene Kalender braucht. Die uebrigen Teilnehmer eines fremden Termins
 * stehen in der Einladung von drueben und nicht hier; wir wissen nichts ueber
 * sie, und mehr zu versprechen waere unredlich.
 *
 * ## Derselbe Vorlauf wie beim eigenen Kalender
 *
 * Ueber {@see ReminderLead}. Ein Mensch, der „30 Minuten vorher" gewaehlt hat,
 * meint das fuer seine Termine und nicht fuer die eine Haelfte davon. Wer
 * „keine" gewaehlt hat, bekommt hier ebenfalls keine.
 *
 * ## Die Doppelungsregel — und warum sie nicht optional ist
 *
 * Gemessen am 29.10.2026 im Peppermint Manager: 21 kommende eigene Termine,
 * 1009 im Outlook-Abo. Ein Termin, den jemand im eigenen System anlegt,
 * erscheint auch im Outlook der Eingeladenen und damit im Abo. Ohne Regel
 * waeren das zwei Meldungen fuer eine Besprechung — und zwar nicht im
 * Ausnahmefall, sondern bei jedem internen Termin.
 *
 * Entschieden am 29.10.2026: Ein Abo-Termin wird NICHT erinnert, wenn ein
 * eigener Termin derselben Person
 *
 * - dieselbe Quell-Kennung traegt (`source_uid` gegen `uid` des eigenen
 *   Termins), ODER
 * - denselben Titel hat und innerhalb von EINER MINUTE beginnt.
 *
 * Der eigene gewinnt, und das ist nicht Willkuer: Nur er hat Teilnehmer, eine
 * Zusage und ein Sprungziel. Der Abo-Termin ist die Kopie.
 *
 * ## Warum der Vergleich im Speicher passiert und nicht in SQL
 *
 * „Start innerhalb einer Minute" ist in SQL je Treiber verschieden
 * (`TIMESTAMPDIFF` gegen `strftime`), und ein `whereRaw` je Treiber waere ein
 * Fehler, der nur in Produktion auftritt. Die eigenen KOMMENDEN Termine sind
 * ausserdem eine Handvoll — 21 gegen 1009. Also einmal je Lauf laden, in eine
 * Menge legen, und dann in konstanter Zeit fragen.
 */
class SubscribedEventReminders implements ErinnerungsAnlass
{
    /** Die eine Quelle, die diese Regel besitzt. Daran haengt das Aufraeumen. */
    public const SOURCE_LEAD = 'subscription:lead';

    /**
     * Die eigenen kommenden Termine je Person — einmal je Lauf geladen.
     *
     * Aufbau: `[person => ['uid' => [kennung => true], 'zeit' => [minute => [titel => [zeitstempel]]]]]`.
     *
     * `null` heisst „noch nicht geladen" und ist deshalb nicht dasselbe wie
     * eine leere Liste.
     *
     * @var array<array-key, array<string, mixed>>|null
     */
    private ?array $eigene = null;

    public function __construct(private readonly ReminderLead $vorlauf) {}

    /** @return array<int, string> */
    public function quellen(): array
    {
        return [self::SOURCE_LEAD];
    }

    /** @return class-string<SubscribedEvent> */
    public function gegenstandsKlasse(): string
    {
        return (string) config('calendar.subscribed_event_model', SubscribedEvent::class);
    }

    public function gegenstaende()
    {
        $klasse = $this->gegenstandsKlasse();

        // Nur was noch kommt, und nur aus Abos, die noch aktiv sind.
        //
        // `whereHas` und nicht ein Join: Wird ein Abo abgeschaltet oder
        // geloescht, fallen seine Termine aus dieser Menge — und der Abgleich
        // nimmt ihre Erinnerungen weg. Genau das soll passieren.
        //
        // Das Abo kommt MIT: Ohne das waere es eine Abfrage je Termin, nur um
        // den Empfaenger zu erfahren. Bei vierstelligen Terminzahlen ist das
        // der Unterschied zwischen einem Lauf und einem Problem.
        return $klasse::query()
            ->with('calendar')
            ->whereHas('calendar', fn ($abfrage) => $abfrage->where('is_active', true))
            ->where('start_datetime', '>', now());
    }

    public function wortlaut(Model $gegenstand, string $quelle): string
    {
        return (string) $gegenstand->title;
    }

    public function empfaenger(Model $gegenstand): int|string|null
    {
        $eigner = $gegenstand->calendar?->user_id;

        return $eigner === null ? null : (int) $eigner;
    }

    /**
     * @return array<string, DateTimeInterface>
     */
    public function momente(Model $gegenstand): array
    {
        $empfaenger = $this->empfaenger($gegenstand);

        if ($empfaenger === null) {
            return [];
        }

        // Ein Termin, den die Person auch als EIGENEN hat, wird nicht doppelt
        // erinnert. Siehe Klassenkommentar.
        if ($this->auchEigener($gegenstand, $empfaenger)) {
            return [];
        }

        $minuten = $this->vorlauf->minutenFuer($empfaenger);

        // „Keine Erinnerung" ist eine Entscheidung. Eine leere Antwort ist
        // zulaessig und heisst genau das — der Abgleich raeumt dann weg, was
        // dieser Termin fuer diese Person noch an Zeilen hat.
        if ($minuten === null) {
            return [];
        }

        return [self::SOURCE_LEAD => LeadMoment::fuer(
            $gegenstand->start_datetime,
            (bool) $gegenstand->all_day,
            $minuten,
        )];
    }

    /**
     * Hat diese Person denselben Termin auch als eigenen?
     */
    private function auchEigener(Model $aboTermin, int $person): bool
    {
        $eigene = $this->eigeneTermine()[$person] ?? null;

        if ($eigene === null) {
            return false;
        }

        $quellKennung = $aboTermin->source_uid;

        if (is_string($quellKennung) && $quellKennung !== '' && isset($eigene['uid'][$quellKennung])) {
            return true;
        }

        $start = $aboTermin->start_datetime;

        if ($start === null) {
            return false;
        }

        $titel = $this->normal((string) $aboTermin->title);
        $sekunde = $start->getTimestamp();

        // Drei Minuten-Eimer, weil „±1 Minute" ueber eine Minutengrenze
        // hinweggehen kann: 09:00:30 und 09:01:10 liegen 40 Sekunden
        // auseinander und in verschiedenen Eimern.
        $minute = intdiv($sekunde, 60);

        foreach ([$minute - 1, $minute, $minute + 1] as $eimer) {
            foreach ($eigene['zeit'][$eimer][$titel] ?? [] as $eigenerStart) {
                if (abs($eigenerStart - $sekunde) <= 60) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Die eigenen kommenden Termine, nach Person sortiert — einmal je Lauf.
     *
     * Nur die KOMMENDEN: Ein vergangener eigener Termin kann keine Doppelung
     * mehr erzeugen, und die Abo-Termine, um die es geht, liegen alle in der
     * Zukunft.
     *
     * @return array<array-key, array<string, mixed>>
     */
    private function eigeneTermine(): array
    {
        if ($this->eigene !== null) {
            return $this->eigene;
        }

        /** @var class-string<CalendarEvent> $klasse */
        $klasse = (string) config('calendar.event_model', CalendarEvent::class);

        $this->eigene = [];

        $klasse::query()
            ->with('attendees')
            ->where($klasse::column('starts_at'), '>', now())
            ->chunkById(500, function ($termine) use ($klasse): void {
                foreach ($termine as $termin) {
                    $start = $termin->field('starts_at');

                    if ($start === null) {
                        continue;
                    }

                    $sekunde = $start instanceof \DateTimeInterface
                        ? $start->getTimestamp()
                        : strtotime((string) $start);

                    $kennung = (string) ($termin->field('uid') ?? '');
                    $titel = $this->normal((string) $termin->field('title'));

                    // Fuer JEDE beteiligte Person — Eigner und Teilnehmer.
                    // Chris sieht einen Termin, den Volker angelegt hat, in
                    // seinem Outlook; die Doppelung entsteht also bei ihm, und
                    // nicht beim Eigner.
                    foreach ($this->beteiligte($termin) as $person) {
                        if ($kennung !== '') {
                            $this->eigene[$person]['uid'][$kennung] = true;
                        }

                        $this->eigene[$person]['zeit'][intdiv($sekunde, 60)][$titel][] = $sekunde;
                    }
                }
            });

        return $this->eigene;
    }

    /**
     * @return array<int, int>
     */
    private function beteiligte(Model $termin): array
    {
        $leute = [];

        $eigner = $termin->field('owner_id');

        if ($eigner !== null) {
            $leute[] = (int) $eigner;
        }

        foreach ($termin->attendees as $teilnehmer) {
            if ($teilnehmer->user_id !== null) {
                $leute[] = (int) $teilnehmer->user_id;
            }
        }

        return array_values(array_unique($leute));
    }

    /**
     * Titel vergleichbar machen.
     *
     * Outlook haengt gern Praefixe an („Zugesagt: ", „Abgelehnt: ") und
     * veraendert Leerraum. Ein Vergleich auf Gleichheit wuerde daran
     * scheitern — und eine Doppelungsregel, die an einem Doppelpunkt
     * scheitert, ist keine.
     */
    private function normal(string $titel): string
    {
        $ohnePraefix = preg_replace('/^\s*(zugesagt|abgelehnt|mit Vorbehalt|accepted|declined|tentative)\s*:\s*/iu', '', $titel);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $ohnePraefix) ?? ''));
    }
}
