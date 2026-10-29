<?php

namespace Peppermint\Calendar\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Sources\EventSourceRegistry;

/**
 * Die Zeilen der Agenda: was als Nächstes kommt, eigene und fremde Termine.
 *
 * ## Warum das im Paket steht
 *
 * „Was kommt als Nächstes" war am 16.10.2026 in zwei Systemen gebaut und in
 * zwei nicht. Die beiden Fassungen unterschieden sich nicht in der Absicht,
 * sondern in Kleinigkeiten, die man erst merkt, wenn man sie vergleicht: Der
 * Manager holte 402 Zeilen weit auch die Kalender von Kollegen, das Brain
 * stellte überfällige Aufgaben oben voran. Beiden gemeinsam war der Kern —
 * eigene Termine im Zeitfenster, dazu die fremden Kalender, nach Tag sortiert.
 *
 * Genau dieser Kern steht hier. Was ein Produkt zusätzlich zeigt, hängt es
 * selbst an: Die Zeilen sind eine Liste, kein Endergebnis.
 *
 * ## Die Form ist die der JS-Liste
 *
 * `AgendaRow` im Frontend liest `date`, `start`, `end`, `title`, `location`,
 * `colour`, `badge`, `recurring`, `dimmed`. Dieselben Namen stehen hier, damit
 * zwischen Server und Liste keine Übersetzung liegt — eine Übersetzung ist die
 * Stelle, an der ein umbenanntes Feld still verschwindet.
 */
final class AgendaAssembler
{
    public function __construct(
        private readonly EventSourceRegistry $sources,
    ) {}

    /**
     * Das Zeitfenster der Agenda — ab heute, so viele Tage wie gewünscht.
     *
     * Gedeckelt auf 90 Tage, weil die Liste sonst zu einem zweiten Kalender
     * wird: Sie trägt jede Zeile einzeln, und bei einem Jahr sind das Tausende.
     * Nach unten auf einen Tag, weil null Tage keine Frage ist, die jemand
     * stellen wollte.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    public function window(int $days, ?string $timezone = null): array
    {
        $days = max(1, min($days, 90));

        $from = CarbonImmutable::now($timezone)->startOfDay();

        return [$from, $from->addDays($days)->endOfDay(), $days];
    }

    /**
     * Die eigenen Termine im Fenster, als Zeilen.
     *
     * `visibleTo` heisst Eigentum ODER Teilnahme — wer eingeladen ist, soll den
     * Termin in seiner Agenda sehen, auch wenn er ihn nicht angelegt hat.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function own(int $userId, DateTimeInterface $from, DateTimeInterface $to, array $hidden = []): Collection
    {
        /*
         * Die Termin-Klasse des PRODUKTS, nicht die des Pakets.
         *
         * Hier stand bis zum 29.10.2026 `CalendarEvent::query()`. Das trifft
         * dieselbe Tabelle und ist trotzdem falsch: Es ist nicht die Klasse,
         * die das Produkt instanziiert — ihr fehlen dessen Beziehungen, Casts
         * und ihr Morph-Typ. In drei von vier Systemen fiel das nicht auf,
         * weil dort beides dasselbe ist.
         */
        $klasse = (string) config('calendar.event_model', CalendarEvent::class);

        return $klasse::query()
            ->visibleTo($userId)
            ->inRange($from, $to)
            ->orderBy($klasse::column('starts_at'))
            // Die Kategorien mitladen, wenn es sie gibt: Ohne das fragt der
            // Rueckfall in `EventKind::categories()` je Termin einzeln, und
            // hier laufen viele durch.
            ->with($this->mitKategorien())
            ->get()
            ->reject(fn (CalendarEvent $event) => in_array($event->field('kind'), $hidden, true))
            ->map(fn (CalendarEvent $event) => $this->row($event))
            ->values();
    }

    /**
     * Die fremden Kalender im Fenster, als Zeilen.
     *
     * Das Vorzeichen `source:` ist dasselbe wie in der Filterleiste — eine
     * versteckte Quelle soll in der Agenda genauso wegbleiben wie im Raster.
     * Stünde die Regel hier anders, wäre die Agenda die Ansicht, in der ein
     * abgeschalteter Kalender wieder auftaucht.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function foreign(int $userId, DateTimeInterface $from, DateTimeInterface $to, array $hidden = []): Collection
    {
        $sichtbare = collect($this->sources->available())
            ->reject(fn ($quelle, string $key) => in_array("source:{$key}", $hidden, true));

        if ($sichtbare->isEmpty()) {
            return collect();
        }

        // Die Namen der Systeme, einmal nachgesehen. `ExternalEvent::toArray()`
        // trägt nur den Schlüssel — und „demo" ist kein Etikett, das jemand
        // lesen will.
        $namen = $sichtbare->map(fn ($quelle) => $quelle->label())->all();

        return collect($this->sources->collect($userId, $from, $to, $sichtbare->keys()->all()))
            ->map(function ($event) use ($namen) {
                $daten = is_array($event) ? $event : $event->toArray();

                $beginn = CarbonImmutable::parse($daten['starts_at']);
                $ende = isset($daten['ends_at']) && $daten['ends_at'] !== null
                    ? CarbonImmutable::parse($daten['ends_at'])
                    : null;

                $ganztags = (bool) ($daten['all_day'] ?? false);

                return [
                    'key' => 'source:'.($daten['source'] ?? '').':'.($daten['id'] ?? ''),
                    'date' => $beginn->toDateString(),
                    'start' => $ganztags ? null : $beginn->format('H:i'),
                    'end' => $ganztags || $ende === null ? null : $ende->format('H:i'),
                    'title' => (string) ($daten['title'] ?? ''),
                    'location' => $daten['location'] ?? null,
                    'colour' => $daten['colour'] ?? null,
                    // Das Etikett nennt das SYSTEM, nicht die Terminart: Die Art
                    // drüben ist ein Begriff des anderen Systems, und sie hier
                    // anzuzeigen hiesse, sie zu erklären.
                    'badge' => $namen[$daten['source'] ?? ''] ?? ($daten['source'] ?? null),
                    'recurring' => (bool) ($daten['recurring'] ?? false),
                    'foreign' => true,
                ];
            })
            ->values();
    }

    /**
     * Eigene und fremde Zeilen in einer Liste, nach Zeit sortiert.
     *
     * Ganztägige Einträge stehen vor den zeitgebundenen desselben Tages: Sie
     * gelten für den ganzen Tag, also auch für die erste Stunde.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(int $userId, int $days = 14, array $hidden = [], ?string $timezone = null): array
    {
        [$from, $to] = $this->window($days, $timezone);

        return $this->own($userId, $from, $to, $hidden)
            ->concat($this->foreign($userId, $from, $to, $hidden))
            // EIN zusammengesetzter Schlüssel, nicht zwei:
            // `sortBy([$a, $b])` sortierte hier nur nach dem ersten, und der
            // ganztägige Eintrag landete hinter dem um zehn. Lexikographisch
            // ist „2026-09-08 " kleiner als „2026-09-08 10:00" — der leere
            // Uhrzeitteil eines ganztägigen Eintrags sortiert also von selbst
            // nach vorn, und das ist richtig: Er gilt für den ganzen Tag,
            // also auch für die erste Stunde.
            ->sortBy(fn (array $zeile) => $zeile['date'].' '.($zeile['start'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * Ein eigener Termin als Zeile.
     *
     * @return array<string, mixed>
     */
    private function row(CalendarEvent $event): array
    {
        $art = $event->kindDefinition();

        /*
         * Jedes Feld ueber `field()`, keines direkt.
         *
         * Hier stand bis zum 29.10.2026 `$event->starts_at` — und in einer
         * Anwendung, die ihre Spalte seit Jahren `start_datetime` nennt, ist
         * das nicht ein anderer Name fuer dasselbe, sondern `null`. Der Aufruf
         * `null->toDateString()` bricht dann mitten in der Seite ab.
         *
         * Das war der Grund, warum der Peppermint Manager seine Agenda selbst
         * gebaut hat: Er konnte diese hier nicht nehmen. Eine Zeile weiter
         * unten stand es uebrigens schon richtig
         * (`field('recurrence_rules')`) — ein halb angewandtes Muster ist
         * schwerer zu sehen als ein fehlendes.
         */
        $beginn = $event->field('starts_at');
        $ende = $event->field('ends_at');
        $ganztags = (bool) $event->field('all_day');

        return [
            'key' => (string) $event->getKey(),
            'id' => $event->getKey(),
            'date' => $beginn->toDateString(),
            'start' => $ganztags ? null : $beginn->format('H:i'),
            'end' => $ganztags || $ende === null ? null : $ende->format('H:i'),
            'title' => (string) $event->field('title'),
            'location' => $event->field('location'),
            // KEINE Farbe: Die Farbtafel liegt im Produkt — der Manager hält
            // sie im Frontend, die Verwaltung im Controller. Die Zeile nennt
            // ihre Art, und das Produkt setzt die Farbe nach, mit derselben
            // Tafel, die auch das Raster färbt. Eine zweite Tafel hier wäre
            // die, die irgendwann abweicht.
            'colour' => null,
            'badge' => $art->label(),
            // `$event->rrule` stand hier bis zum 19.10.2026 — und die
            // Eigenschaft gibt es am Modell GAR NICHT. Der Wert war also immer
            // `false`, in allen vier Produkten, seit es das Feld gibt. Kein
            // Test hat es gemerkt, weil `false` eine plausible Antwort ist.
            'recurring' => $event->field('recurrence_rules') !== null,
            'kind' => $event->field('kind'),
            // Vorbei ist abgeblendet. Was sonst noch abgeblendet gehört —
            // abgesagt, erledigt — weiss das Produkt und setzt es nach.
            'dimmed' => $ende !== null && $ende->isPast(),
            'categories' => $art->categories($event),
        ];
    }

    /**
     * Was vorgeladen wird — leer, wenn dieses System keine Zuordnung haelt.
     *
     * `with('assignedCategories')` auf einem System ohne die Tabelle waere
     * keine leere Antwort, sondern ein Fehler. Siehe
     * {@see CategoryRegistry::assignmentsAvailable()}.
     *
     * @return array<int, string>
     */
    private function mitKategorien(): array
    {
        $registry = app(CategoryRegistry::class);

        return $registry->enabled() && $registry->assignmentsAvailable()
            ? ['assignedCategories']
            : [];
    }
}
