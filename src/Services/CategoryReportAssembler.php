<?php

namespace Peppermint\Calendar\Services;

use DateTimeInterface;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Recurrence\Occurrence;
use Peppermint\Calendar\Recurrence\OccurrenceExpander;

/**
 * Wie viel Zeit ging in welche Kategorie?
 *
 * ## Warum das im Paket steht
 *
 * Diese Auswertung gab es am 18.10.2026 nur im Manager (165 Zeilen Controller,
 * 420 Zeilen Seite). CRM, Verwaltung und Brain hatten sie nicht — obwohl die
 * Frage „wofür ist meine Woche draufgegangen" in jedem System dieselbe ist,
 * sobald Termine Kategorien tragen.
 *
 * Gemeinsam ist der Kern: Termine im Fenster holen, Serien AUFKLAPPEN, je
 * Kategorie Dauer und Anzahl summieren. Was ein Produkt zusätzlich gruppiert —
 * der Manager nach Projekten, das CRM könnte nach Geschäften — hängt es selbst
 * an.
 *
 * ## Die eine Regel, die man nicht vergessen darf
 *
 * **Gezählt wird der Termin, den es tatsächlich gab, nicht die Regel.** Eine
 * wöchentliche Besprechung steht sonst mit einer Stunde im Bericht statt mit
 * vieren. Deshalb geht alles durch den {@link OccurrenceExpander} — und
 * deshalb ist diese Klasse kein `groupBy` auf einer Abfrage, was sie auf den
 * ersten Blick sein könnte.
 */
final class CategoryReportAssembler
{
    public function __construct(
        private readonly OccurrenceExpander $expander,
    ) {}

    /**
     * Die Auswertung für eine Person in einem Fenster.
     *
     * `$to` ist ein Zeitpunkt und exklusiv — dieselbe Lesart wie `inRange()`
     * und wie der Expander. Wer „einschliesslich dieses Tages" meint, gibt
     * dessen Ende herein.
     *
     * @return array{
     *     categories: array<int, array<string, mixed>>,
     *     uncategorized: array<string, mixed>,
     *     groups: array<int, array<string, mixed>>,
     *     total: array<string, mixed>
     * }
     */
    public function forUser(int $userId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $vorkommen = $this->expander->expand(
            CalendarEvent::query()
                ->visibleTo($userId)
                // Grosszuegiger abfragen als das Fenster: Eine Serie, die davor
                // beginnt, hat ihre Vorkommen MITTEN im Fenster. Wer nur
                // `inRange` fragt, verliert genau die Serien, um die es geht.
                ->where(CalendarEvent::column('starts_at'), '<', $to)
                // Vorladen, sonst fragt der Rueckfall in
                // `EventKind::categories()` je Termin einzeln — und die
                // Auswertung laeuft ueber ein Jahr.
                ->with($this->mitKategorien())
                ->get(),
            $from,
            $to,
        );

        /** @var array<string, array{label: string, colour: string|null, count: int, minutes: int}> $nachKategorie */
        $nachKategorie = [];
        $ohne = ['count' => 0, 'minutes' => 0];

        /** @var array<string, array{label: string, count: int, minutes: int}> $nachGruppe */
        $nachGruppe = [];

        foreach ($vorkommen as $eintrag) {
            $minuten = $this->minuten($eintrag);
            $etiketten = $eintrag->event->kindDefinition()->categories($eintrag->event);

            if ($etiketten === []) {
                $ohne['count']++;
                $ohne['minutes'] += $minuten;

                // Was ohne Kategorie bleibt, AUFSCHLUESSELN, wenn die
                // Terminart sagen kann, worueber.
                //
                // Ein Zeitblock traegt keine Kategorie — er zeigt auf eine
                // Aufgabe, und die trägt den Zweck praeziser, als ein Etikett
                // es koennte. Trotzdem ist seine Zeit nicht nichts: Im
                // Manager sind 483 von 1211 Terminen solche Bloecke, 40
                // Prozent des Kalenders. Die standen bisher in einer einzigen
                // Sammelzeile.
                //
                // ABGELEITET, nicht gewaehlt: Das Etikett kommt aus dem
                // Subjekt (Projekt, Ticketbereich), nicht aus einer Wahl.
                // Deshalb eine EIGENE Liste und keine Zeile zwischen den
                // Kategorien — sonst steht Gewaehltes neben Abgeleitetem und
                // sieht gleich aus.
                $gruppe = $eintrag->event->kindDefinition()->reportGroup($eintrag->event);

                if ($gruppe !== null && $gruppe !== '') {
                    $nachGruppe[$gruppe] ??= ['label' => $gruppe, 'count' => 0, 'minutes' => 0];
                    $nachGruppe[$gruppe]['count']++;
                    $nachGruppe[$gruppe]['minutes'] += $minuten;
                }

                continue;
            }

            foreach ($etiketten as $etikett) {
                $schluessel = (string) $etikett;

                $nachKategorie[$schluessel] ??= [
                    'label' => $schluessel,
                    'count' => 0,
                    'minutes' => 0,
                ];

                // Ein Termin mit zwei Kategorien zaehlt bei BEIDEN voll.
                //
                // Die Alternative waere, die Dauer zu teilen — und das waere
                // falsch: „Kundentermin" und „abrechenbar" sind keine zwei
                // halben Stunden, sondern eine Stunde, die beides ist. Die
                // Summe ueber alle Kategorien kann deshalb groesser sein als
                // die Gesamtzeit; `total` unten zaehlt jeden Termin einmal.
                $nachKategorie[$schluessel]['count']++;
                $nachKategorie[$schluessel]['minutes'] += $minuten;
            }
        }

        $kategorien = array_values($nachKategorie);

        usort($kategorien, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        $gesamtMinuten = $vorkommen->sum(fn (Occurrence $eintrag) => $this->minuten($eintrag));

        $gruppen = array_values($nachGruppe);

        usort($gruppen, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        return [
            'categories' => array_map($this->mitStunden(...), $kategorien),
            // Die Aufschluesselung dessen, was ohne Kategorie bleibt. Ihre
            // Summe ist deshalb nie groesser als `uncategorized` — und
            // genau das prueft ein Test: Ein Termin ohne Kategorie zaehlt
            // hier EINMAL, nicht in mehreren Gruppen.
            'groups' => array_map($this->mitStunden(...), $gruppen),
            'uncategorized' => $this->mitStunden([
                'label' => null,
                'count' => $ohne['count'],
                'minutes' => $ohne['minutes'],
            ]),
            'total' => $this->mitStunden([
                'label' => null,
                'count' => $vorkommen->count(),
                'minutes' => (int) $gesamtMinuten,
            ]),
        ];
    }

    /** Die Dauer EINES Vorkommens, nicht die der Serienregel. */
    private function minuten(Occurrence $eintrag): int
    {
        return (int) $eintrag->startsAt->diffInMinutes($eintrag->endsAt);
    }

    /**
     * Stunden dazu, auf zwei Stellen.
     *
     * Beides, nicht nur Stunden: Minuten sind die verlustfreie Zahl zum
     * Weiterrechnen, Stunden die, die man liest. Wer nur Stunden liefert,
     * zwingt jede Oberfläche zum Zurückrechnen — und drei Oberflächen runden
     * dann verschieden.
     *
     * @param  array<string, mixed>  $zeile
     * @return array<string, mixed>
     */
    private function mitStunden(array $zeile): array
    {
        return [...$zeile, 'hours' => round($zeile['minutes'] / 60, 2)];
    }

    /**
     * @return array<int, string>
     *
     * @see AgendaAssembler::mitKategorien() — dieselbe Begruendung.
     */
    private function mitKategorien(): array
    {
        $registry = app(CategoryRegistry::class);

        return $registry->enabled() && $registry->assignmentsAvailable()
            ? ['assignedCategories']
            : [];
    }
}
