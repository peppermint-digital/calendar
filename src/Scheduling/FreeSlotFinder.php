<?php

namespace Peppermint\Calendar\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Recurrence\Occurrence;
use Peppermint\Calendar\Recurrence\OccurrenceExpander;

/**
 * Wann sind alle frei? — die Terminfindung.
 *
 * ## Warum das im Paket steht
 *
 * Am 16.10.2026 gab es sie nur im Manager (275 Zeilen Controller, 506 Zeilen
 * Seite). CRM, Verwaltung und Brain hatten sie nicht — obwohl „wann passt es
 * allen" eine Frage ist, die man in jedem System stellt, sobald mehr als eine
 * Person im Kalender steht.
 *
 * Die Rechnung ist in allen vier dieselbe: Tage abschreiten, je Tag das
 * Arbeitsfenster in Scheiben schneiden, je Scheibe fragen, wer schon etwas
 * vorhat. Nur die LETZTE Frage ist produkteigen — der Manager rechnet Urlaub
 * und Aufgabengruppen mit, die Verwaltung müsste Zeitblöcke mitrechnen.
 * Deshalb nimmt diese Klasse zusätzliche Belegungen HEREIN statt sie zu
 * erraten.
 *
 * ## Zwei Dinge, die hier festgelegt sind
 *
 * **Angrenzend ist nicht überlappend.** Ein Termin von 10 bis 11 und eine
 * Scheibe von 11 bis 12 liegen hintereinander, nicht übereinander. Dieselbe
 * Regel wie im {@link ScheduleConflictFinder} — und derselbe Grund: Wer das
 * verwechselt, baut einen Planer, der jede zweite Scheibe ohne sichtbaren
 * Grund ablehnt.
 *
 * **Ganztägige Termine belegen den ganzen Tag.** Sie haben keine Uhrzeit, also
 * gibt es keine Scheibe, in die sie fallen — ohne Sonderbehandlung wäre jemand
 * mit einem Urlaubstag den ganzen Tag „frei".
 */
final class FreeSlotFinder
{
    public function __construct(
        private readonly OccurrenceExpander $expander,
    ) {}

    /**
     * Eine Belegung: wer, von wann bis wann.
     *
     * Als eigener Begriff und nicht als `CalendarEvent`, damit ein Produkt auch
     * Urlaub, Abwesenheit oder Fremdkalender hereingeben kann — Dinge, die
     * keine Termine sind, aber Zeit belegen.
     *
     * @param  array<int, array{user: int, start: CarbonInterface, end: CarbonInterface, allDay?: bool}>  $extra
     * @return array<int, array<string, mixed>>
     */
    public function grid(
        array $userIds,
        CarbonInterface $from,
        CarbonInterface $to,
        int $workingHoursStart = 8,
        int $workingHoursEnd = 18,
        int $slotMinutes = 30,
        array $extra = [],
        bool $includeWeekends = false,
    ): array {
        // `endOfDay` am Ende, nicht der nackte Wert.
        //
        // `$to` kommt als Tag herein (`2026-09-07`), also als 00:00 dieses
        // Tages. Mit dem nackten Wert fragte `inRange($from, $to)` ein Fenster
        // von null Laenge ab, sobald Anfang und Ende derselbe Tag sind — und
        // fand nichts. Sichtbar wurde das als Planer, der ALLES frei nennt:
        // der schlimmste Fehler, den er machen kann, weil er plausibel aussieht.
        $belegt = array_merge(
            $this->fromEvents($userIds, $from->copy()->startOfDay(), $to->copy()->endOfDay()),
            $extra,
        );

        $raster = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $tag) {
            $tag = CarbonImmutable::parse($tag);
            $wochenende = $tag->isWeekend();

            $scheiben = [];
            $zeiger = $tag->setTime($workingHoursStart, 0);
            $schluss = $tag->setTime($workingHoursEnd, 0);

            while ($zeiger < $schluss) {
                $ende = $zeiger->addMinutes($slotMinutes);

                $beschaeftigt = array_values(array_filter(
                    $userIds,
                    fn (int $userId) => $this->busy($belegt, $userId, $zeiger, $ende),
                ));

                $scheiben[] = [
                    'start' => $zeiger->format('H:i'),
                    'end' => $ende->format('H:i'),
                    'startsAt' => $zeiger->toIso8601String(),
                    'endsAt' => $ende->toIso8601String(),
                    // Am Wochenende ist niemand „frei" — er hat nur nichts
                    // vor. Das ist ein Unterschied, den ein Planer zeigen
                    // muss, sonst schlaegt er Sonntagmorgen als beste Zeit vor.
                    'free' => $beschaeftigt === [] && ($includeWeekends || ! $wochenende),
                    'busyCount' => count($beschaeftigt),
                    'busyUsers' => $beschaeftigt,
                    'total' => count($userIds),
                ];

                $zeiger = $ende;
            }

            $raster[] = [
                'date' => $tag->toDateString(),
                'weekend' => $wochenende,
                'today' => $tag->isToday(),
                'slots' => $scheiben,
            ];
        }

        return $raster;
    }

    /**
     * Die Belegungen aus den Terminen dieser Personen.
     *
     * Über `owner_id` UND Teilnahme: Wer zu einem Termin eingeladen ist, hat
     * dann keine Zeit — auch wenn der Termin jemand anderem gehört. Ohne das
     * schlägt die Terminfindung genau die Stunde vor, in der die halbe Runde
     * schon in einer anderen Besprechung sitzt.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{user: int, start: CarbonInterface, end: CarbonInterface, allDay: bool}>
     */
    private function fromEvents(array $userIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($userIds === []) {
            return [];
        }

        $termine = CalendarEvent::query()
            // Grosszuegiger abfragen als das Fenster, dann entfalten.
            //
            // `inRange` stand hier bis zum 19.10.2026 — und das war falsch,
            // gleich doppelt: Eine Serie, die VOR dem Fenster beginnt, wurde
            // gar nicht gefunden, und eine gefundene zaehlte nur mit ihrem
            // ersten Termin. Eine woechentliche Besprechung blockierte also
            // genau eine Stunde statt jeder Woche.
            //
            // Das ist der schlimmste Fehler, den ein Planer machen kann: Er
            // bietet eine Zeit an, in der die halbe Runde schon sitzt, und
            // sieht dabei vollkommen plausibel aus. Dieselbe Begruendung
            // hatte die Kategorie-Auswertung, solange sie im Paket lag
            // (v0.59.0 bis v0.84.0) — die Ueberschneidung, nicht der Beginn,
            // ist die Frage, die ein Mensch meint.
            ->where(CalendarEvent::column('starts_at'), '<', $to)
            ->where(function ($abfrage) use ($userIds) {
                $abfrage->whereIn(CalendarEvent::column('owner_id'), $userIds)
                    ->orWhereHas('attendees', fn ($a) => $a->whereIn('user_id', $userIds));
            })
            ->with('attendees:id,event_id,user_id')
            ->get();

        $belegt = [];

        foreach ($this->expander->expand($termine, $from, $to) as $vorkommen) {
            /** @var Occurrence $vorkommen */
            $termin = $vorkommen->event;

            // Eine Belegung je betroffener Person: Der Eigentümer und jeder
            // Teilnehmer, der in der Frage vorkommt.
            $betroffene = collect([$termin->owner_id])
                ->concat($termin->attendees->pluck('user_id'))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->intersect($userIds)
                ->unique();

            foreach ($betroffene as $userId) {
                $belegt[] = [
                    'user' => $userId,
                    // Die Zeiten des VORKOMMENS, nicht die der Regel.
                    'start' => $vorkommen->startsAt,
                    'end' => $vorkommen->endsAt,
                    'allDay' => (bool) $termin->all_day,
                ];
            }
        }

        return $belegt;
    }

    /**
     * Hat diese Person in dieser Scheibe schon etwas vor?
     *
     * @param  array<int, array<string, mixed>>  $belegt
     */
    private function busy(array $belegt, int $userId, CarbonInterface $start, CarbonInterface $end): bool
    {
        foreach ($belegt as $eintrag) {
            if ((int) $eintrag['user'] !== $userId) {
                continue;
            }

            // Ganztägig belegt den ganzen Tag: Es gibt keine Scheibe, in die
            // der Termin fällt, also wäre jemand mit einem Urlaubstag sonst
            // den ganzen Tag frei.
            //
            // Und zwar JEDEN Tag zwischen Anfang und Ende, nicht nur den
            // ersten. Hier stand bis zum 19.10.2026 `isSameDay($eintrag
            // ['start'])` — eine Urlaubswoche sperrte damit den Montag und gab
            // Dienstag bis Freitag frei. Gefunden durch einen Test über einen
            // Tag MITTEN in einem längeren Urlaub; der Fall mit nur einem Tag
            // war grün und hat den Fehler verdeckt.
            if (($eintrag['allDay'] ?? false) === true) {
                $vonTag = CarbonImmutable::instance($eintrag['start'])->startOfDay();
                $bisTag = CarbonImmutable::instance($eintrag['end'])->endOfDay();

                if ($start >= $vonTag && $start <= $bisTag) {
                    return true;
                }

                continue;
            }

            // Angrenzend ist nicht überlappend: `<` und `>`, nicht `<=`/`>=`.
            if ($eintrag['start'] < $end && $eintrag['end'] > $start) {
                return true;
            }
        }

        return false;
    }
}
