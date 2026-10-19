<?php

namespace Peppermint\Calendar\Services;

use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Categories\EventCategories;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Models\CalendarEventAttendee;

/**
 * Ein Termin in der Form, die die Einzelansicht liest.
 *
 * ## Warum das im Paket steht
 *
 * „Einen Termin ansehen" gab es am 16.10.2026 in vier Ausführungen, und zwar
 * in vier verschiedenen *Interaktionsmodellen*: der Manager eine eigene Seite
 * (604 Zeilen), das Brain einen Dialog im Kalender, CRM und Verwaltung führten
 * den Klick direkt auf die Bearbeiten-Maske. Das letzte ist das schlechteste —
 * es vermischt Lesen mit Ändern, und wer nur nachsehen wollte, wann der Termin
 * ist, steht in einem Formular.
 *
 * Die Felder sind in allen vier dieselben. Die Form hier ist die, die
 * `EventDetail` im Frontend liest — dieselben Namen, damit dazwischen keine
 * Übersetzung liegt. Eine Übersetzung ist die Stelle, an der ein umbenanntes
 * Feld still verschwindet.
 *
 * ## Was es nicht tut
 *
 * Es prüft keine Rechte. Ob jemand diesen Termin sehen darf, weiss nur das
 * Produkt: Der Manager kennt private Termine, Projektleitungen und Verwalter,
 * das Brain kennt Tresor-Termine. Ein `visibleTo` im Paket deckt nur Eigentum
 * ODER Teilnahme ab — das ist die UNTERGRENZE, nicht die Regel. Deshalb liegt
 * die Entscheidung beim Aufrufer, und diese Klasse formt nur, was er ihr gibt.
 */
final class EventDetailPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(CalendarEvent $event): array
    {
        $art = $event->kindDefinition();

        $ende = $event->ends_at;
        $mehrtaegig = $ende !== null && ! $ende->isSameDay($event->starts_at);

        return [
            'id' => $event->id,
            'title' => (string) $event->title,
            'date' => $event->starts_at->toDateString(),
            // Nur bei mehrtägigen gesetzt: Ein `endDate`, das immer dem Beginn
            // gleicht, lässt die Ansicht jeden Termin als Zeitraum schreiben.
            'endDate' => $mehrtaegig ? $ende->toDateString() : null,
            'start' => $event->all_day ? null : $event->starts_at->format('H:i'),
            'end' => $event->all_day || $ende === null ? null : $ende->format('H:i'),
            'allDay' => (bool) $event->all_day,
            'location' => $event->location,
            'description' => $event->description,
            'kindLabel' => $art->label(),
            // KEINE Farbe: Die Farbtafel liegt im Produkt — dieselbe
            // Entscheidung wie beim `AgendaAssembler`. Eine zweite Tafel hier
            // wäre die, die irgendwann abweicht.
            'colour' => null,
            'kind' => $event->kind,
            'recurring' => $event->rrule !== null,
            'attendees' => $this->attendees($event),
            'categories' => $art->categories($event),
            // Die Slugs dazu, nicht nur die Etiketten: Die Detailansicht
            // kann eine Kategorie nur umschalten, wenn sie ihren Schluessel
            // kennt — und ein Etikett ist keiner, es darf umbenannt werden.
            'categorySlugs' => $this->slugs($event, $art),
        ];
    }

    /**
     * Die Teilnehmer, mit Namen und Antwort.
     *
     * Ein Teilnehmer kann ein Benutzer sein oder nur eine Mailadresse — bei
     * externen Gästen gibt es kein Konto. `name` steht dafür als Spalte in der
     * Tabelle; fehlt beides, bleibt die Adresse. Niemand heisst „—", und eine
     * leere Marke in der Ansicht sieht nach einem Fehler aus.
     *
     * @return array<int, array<string, string|null>>
     */
    /**
     * Die Slugs der Kategorien dieses Termins.
     *
     * Zwei Riegel, nicht einer: Eine Art, die keine Kategorien traegt, hat
     * keine — und ein System ohne die Zuordnungstabelle des Pakets wuerde beim
     * Fragen in eine fehlende Tabelle laufen. Dieselbe Ruecksicht wie in
     * {@see \Peppermint\Calendar\Kinds\EventKind::categories()}; sie macht
     * den Unterschied zwischen einem additiven Update und einem Ausfall.
     *
     * @return array<int, string>
     */
    private function slugs(CalendarEvent $event, EventKind $art): array
    {
        if (! $art->usesCategories()) {
            return [];
        }

        $registry = app(CategoryRegistry::class);

        if (! $registry->enabled() || ! $registry->assignmentsAvailable()) {
            return [];
        }

        return app(EventCategories::class)->slugsOf($event);
    }

    private function attendees(CalendarEvent $event): array
    {
        return $event->attendees()
            ->with('user')
            ->get()
            ->map(fn (CalendarEventAttendee $person) => [
                'name' => $person->user?->name
                    ?? $person->name
                    ?? $person->email
                    ?? '',
                // `pending` ist der Vorgabewert der Spalte und sagt nichts —
                // „eingeladen und noch nicht geantwortet" ist der Normalfall.
                // Angezeigt wird nur eine echte Antwort.
                'status' => in_array($person->status, ['accepted', 'declined', 'tentative'], true)
                    ? $person->status
                    : null,
            ])
            ->reject(fn (array $person) => $person['name'] === '')
            ->values()
            ->all();
    }
}
