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
    public function own(int $userId, DateTimeInterface $from, DateTimeInterface $to, array $hidden = [], ?string $timezone = null): Collection
    {
        $klasse = $this->terminKlasse();

        return $this->eigeneAbfrage($userId)
            ->inRange($from, $to)
            ->orderBy($klasse::column('starts_at'))
            // Die Kategorien mitladen, wenn es sie gibt: Ohne das fragt der
            // Rueckfall in `EventKind::categories()` je Termin einzeln, und
            // hier laufen viele durch.
            ->with($this->mitKategorien())
            ->get()
            ->reject(fn (CalendarEvent $event) => in_array($event->field('kind'), $hidden, true))
            ->map(fn (CalendarEvent $event) => $this->row($event, $timezone))
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
    public function foreign(int $userId, DateTimeInterface $from, DateTimeInterface $to, array $hidden = [], ?string $timezone = null): Collection
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

        /*
         * Und ihre Farben, aus demselben Grund.
         *
         * Hier stand bis zum 29.10.2026 nur `$daten['colour'] ?? null` — und
         * das war IMMER null: Weder `ExternalEvent::toArray()` noch das
         * Quellenverzeichnis traegt eine Farbe, die Quelle selbst kennt sie
         * (`EventSource::colour()`). Die Zeile sah also nach einer Weitergabe
         * aus und gab nichts weiter.
         *
         * Gemerkt hat es niemand, weil jedes Produkt die Farbe ohnehin selbst
         * nachgesucht hat — AI Brain mit einem `sources.find()` je Zeile im
         * Frontend. Ein Feld, das immer leer ist, erzeugt genau diese Art
         * Doppelarbeit: Es sieht benutzt aus.
         *
         * Eine Farbe am EINZELNEN Termin gewinnt weiter, falls eine Quelle sie
         * mitgibt — die Quelle beschreibt sich, der Termin widerspricht.
         */
        $farben = $sichtbare->map(fn ($quelle) => $quelle->colour())->all();

        return collect($this->sources->collect($userId, $from, $to, $sichtbare->keys()->all()))
            ->map(function ($event) use ($namen, $farben, $timezone) {
                $daten = is_array($event) ? $event : $event->toArray();

                $beginn = $this->inZone(CarbonImmutable::parse($daten['starts_at']), $timezone);
                $ende = isset($daten['ends_at']) && $daten['ends_at'] !== null
                    ? $this->inZone(CarbonImmutable::parse($daten['ends_at']), $timezone)
                    : null;

                $ganztags = (bool) ($daten['all_day'] ?? false);

                return [
                    'key' => 'source:'.($daten['source'] ?? '').':'.($daten['id'] ?? ''),
                    'date' => $beginn->toDateString(),
                    'start' => $ganztags ? null : $beginn->format('H:i'),
                    'end' => $ganztags || $ende === null ? null : $ende->format('H:i'),
                    'title' => (string) ($daten['title'] ?? ''),
                    'location' => $daten['location'] ?? null,
                    'colour' => $daten['colour'] ?? ($farben[$daten['source'] ?? ''] ?? null),
                    // Das Etikett nennt das SYSTEM, nicht die Terminart: Die Art
                    // drüben ist ein Begriff des anderen Systems, und sie hier
                    // anzuzeigen hiesse, sie zu erklären.
                    'badge' => $namen[$daten['source'] ?? ''] ?? ($daten['source'] ?? null),
                    'recurring' => (bool) ($daten['recurring'] ?? false),
                    'foreign' => true,
                    /*
                     * Der Weg ins Heimatsystem. Ein fremder Termin hat hier
                     * keine Einzelansicht — ein Klick auf die Zeile muss
                     * dorthin fuehren, wo er gepflegt wird.
                     *
                     * `ExternalEvent` traegt das Feld schon; es fehlte nur in
                     * der Zeile, und jedes Produkt hat es sich deshalb selbst
                     * aus den Rohdaten geholt.
                     */
                    'url' => $daten['url'] ?? null,
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

        return $this->own($userId, $from, $to, $hidden, $timezone)
            ->concat($this->foreign($userId, $from, $to, $hidden, $timezone))
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
     * Die Termin-Klasse des PRODUKTS, nicht die des Pakets.
     *
     * Hier stand bis zum 29.10.2026 `CalendarEvent::query()`. Das trifft
     * dieselbe Tabelle und ist trotzdem falsch: Es ist nicht die Klasse, die
     * das Produkt instanziiert — ihr fehlen dessen Beziehungen, Casts und ihr
     * Morph-Typ. In drei von vier Systemen fiel das nicht auf, weil dort
     * beides dasselbe ist.
     *
     * @return class-string<CalendarEvent>
     */
    protected function terminKlasse(): string
    {
        return (string) config('calendar.event_model', CalendarEvent::class);
    }

    /**
     * Welche eigenen Termine ueberhaupt in Frage kommen — die Naht fuer ein
     * Produkt mit einer breiteren Regel.
     *
     * ## Warum das ueberschreibbar ist
     *
     * `visibleTo` heisst in diesem Paket „Eigentum ODER Teilnahme". Das ist die
     * richtige Vorgabe und fuer drei von vier Systemen die ganze Wahrheit.
     *
     * Der Peppermint Manager hat eine breitere: Termine ohne Eigner gelten fuer
     * alle, eine Vorgesetzte sieht die ihrer Leute, und ein Zeitblock folgt der
     * Sichtbarkeit seiner AUFGABE und nicht der des Termins. Gemessen am
     * 29.10.2026 stand diese Regel dort ZWEIMAL — 819 Zeichen, zeichengleich,
     * einmal im Kalender-Dienst und einmal in der Agenda.
     *
     * Dafuer genuegt NICHT, `scopeVisibleTo` im Produkt zu verbreitern: Daran
     * haengen auch das MCP-Werkzeug, der ICS-Feed und die Kategorien. Wer die
     * Agenda breiter machen will, wuerde dort Rechte mitverschieben — und zwar
     * lautlos.
     *
     * ## Hier stand am 29.10.2026 fuer drei Stunden ein Vertrag, und er ist weg
     *
     * `AgendaSichtbarkeit` (v0.92.0) war als optionale Abhaengigkeit gedacht,
     * damit der Manager seine breite Regel einhaengen kann. Gebaut habe ich sie
     * in der Annahme, der Manager-Agenda-Controller wechsle auf diesen
     * Zusammensteller.
     *
     * Er wechselt nicht, und das ist gemessen: Seine Seite braucht an jedem
     * Termin Felder, die es hier nicht gibt und nicht geben soll — Projekt,
     * Aufgabe, Kategorie, den Namen der Person fuer die Team-Ansicht,
     * Aufgabenstatus. Ein Wechsel haette entweder diese Felder ins Paket
     * gezogen (ein Kalender-Paket, das Aufgabenstatus kennt) oder sie per
     * zweiter Abfrage nachgeholt. Beides schlechter als der Zustand vorher.
     *
     * Damit hatte der Vertrag keinen Abrufer — und eine unbenutzte
     * Schnittstelle in einem geteilten Paket kostet dauerhaft: Jemand liest sie
     * als Angebot und baut darauf. Entfernt in v0.95.0.
     *
     * **Wer sie wieder braucht**, baut sie mit Abrufer im selben Schritt. Die
     * Form war richtig und steht zum Nachlesen in v0.92.0 bis v0.94.0: ein
     * Vertrag als optionale Abhaengigkeit, keine Unterklasse (diese Klasse ist
     * `final`, weil wer die ZEILEN-Form ueberschreiben kann, die Liste im
     * Frontend von ihrem Server entkoppelt), und kein Konfigurationswert mit
     * einem Scope-Namen (der faellt bei einem Tippfehler still auf die Vorgabe
     * zurueck).
     *
     * @return \Illuminate\Database\Eloquent\Builder<CalendarEvent>
     */
    private function eigeneAbfrage(int $userId)
    {
        $klasse = $this->terminKlasse();

        return $klasse::query()->visibleTo($userId);
    }

    /**
     * Ein eigener Termin als Zeile.
     *
     * @return array<string, mixed>
     */
    private function row(CalendarEvent $event, ?string $timezone = null): array
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
        $beginn = $this->inZone($event->field('starts_at'), $timezone);
        $ende = $this->inZone($event->field('ends_at'), $timezone);
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
            /*
             * Woran der Termin haengt — eine Aufgabe, ein Vorgang, ein Ticket.
             *
             * Zwei KERNSPALTEN dieses Pakets, und deshalb gehoeren sie in die
             * Zeile: Ohne sie muss jedes Produkt, das von der Agenda aus zur
             * Sache springen will, die Termine ein zweites Mal laden und
             * zuordnen. Das Brain tat genau das in TypeScript.
             *
             * Was die Verweise BEDEUTEN, steht weiter nicht hier: `task` ist
             * ein Begriff des Produkts, und die Adresse dorthin kennt nur es.
             */
            'subject_type' => $event->field('subject_type'),
            'subject_id' => $event->field('subject_id'),
            // Vorbei ist abgeblendet. Was sonst noch abgeblendet gehört —
            // abgesagt, erledigt — weiss das Produkt und setzt es nach.
            'dimmed' => $ende !== null && $ende->isPast(),
            'categories' => $art->categories($event),
        ];
    }

    /**
     * Denselben Zeitpunkt in der Zone, in der er angezeigt wird.
     *
     * ## Warum das nicht nur das Fenster betrifft
     *
     * `rows()` nahm bis zum 29.10.2026 eine Zone und benutzte sie nur fuer das
     * FENSTER. Formatiert wurde danach in der Zone der Anwendung — die Agenda
     * suchte also die richtigen Termine und schrieb die falsche Uhrzeit daneben.
     *
     * Gefunden in AI Brain: Dort schreibt der Kalender-Speicher ausdruecklich
     * in `Europe/Berlin`, die Anwendung laeuft auf UTC. Ein Termin um 09:00
     * stand in der Agenda als 08:00.
     *
     * In CRM und Verwaltung fiel es nicht auf, und das ist kein Zufall: Dort
     * schreibt und liest alles in derselben Zone, und ohne Angabe bleibt es
     * genau dabei. Deshalb aendert dieser Fix dort nichts.
     *
     * Die ZONE betrifft auch das Datum: Ein Termin um 00:30 Berliner Zeit liegt
     * in UTC am Tag davor. Wer nur die Uhrzeit umrechnet, haengt die Zeile unter
     * die falsche Tagesueberschrift.
     */
    private function inZone(mixed $zeitpunkt, ?string $timezone): mixed
    {
        if ($zeitpunkt === null || $timezone === null) {
            return $zeitpunkt;
        }

        return CarbonImmutable::parse($zeitpunkt)->setTimezone($timezone);
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
