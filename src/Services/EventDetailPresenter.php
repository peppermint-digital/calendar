<?php

namespace Peppermint\Calendar\Services;

use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Categories\EventCategories;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Models\CalendarEvent;

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

        // Alles ueber `field()` und NICHT ueber `$event->starts_at`.
        //
        // Die Spalten heissen nicht in jedem Produkt gleich: Im Manager steht
        // `start_datetime`, `end_datetime`, `user_id`. Der direkte Zugriff gab
        // dort `null` zurueck, und die Einzelansicht lief auf einen 500er —
        // waehrend sie in CRM, Verwaltung und AI Brain lief, weil die Spalten
        // dort zufaellig so heissen wie im Paket.
        //
        // Gefunden am 19.10.2026 beim Umstellen der Manager-Einzelansicht
        // (#8307). Genau dafuer gibt es `field()`, und genau deshalb steht in
        // seinem Docblock „package code can say `$event->field('starts_at')`
        // without knowing".
        $beginn = $event->field('starts_at');
        $ende = $event->field('ends_at');
        $ganztags = (bool) $event->field('all_day');
        $mehrtaegig = $ende !== null && ! $ende->isSameDay($beginn);

        return [
            'id' => $event->getKey(),
            'title' => (string) $event->field('title'),
            'date' => $beginn->toDateString(),
            // Nur bei mehrtägigen gesetzt: Ein `endDate`, das immer dem Beginn
            // gleicht, lässt die Ansicht jeden Termin als Zeitraum schreiben.
            'endDate' => $mehrtaegig ? $ende->toDateString() : null,
            'start' => $ganztags ? null : $beginn->format('H:i'),
            'end' => $ganztags || $ende === null ? null : $ende->format('H:i'),
            'allDay' => $ganztags,
            'location' => $event->field('location'),
            'description' => $event->field('description'),
            'kindLabel' => $art->label(),
            // KEINE Farbe: Die Farbtafel liegt im Produkt — dieselbe
            // Entscheidung wie beim `AgendaAssembler`. Eine zweite Tafel hier
            // wäre die, die irgendwann abweicht.
            'colour' => null,
            'kind' => $event->field('kind'),
            'recurring' => $event->field('recurrence_rules') !== null,
            // Privat heisst: nur fuer die eigene Person sichtbar. Abgeleitet
            // aus `visibility`, weil das die Spalte ist, auf die sich auch der
            // ICS-Export stuetzt — zwei Quellen fuer dieselbe Aussage waeren
            // zwei Gelegenheiten, sie verschieden zu beantworten.
            //
            // Ein Produkt mit einem eigenen Schalter (der Manager hat
            // `is_private`) ueberschreibt den Wert beim Zusammensetzen.
            'private' => in_array($event->field('visibility'), ['private', 'confidential'], true),
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

    /**
     * Die Antwort eines Teilnehmers — als Zeichenkette, oder null.
     *
     * ## Warum hier ein Enum ankommen kann
     *
     * Die Spalte ist eine Zeichenkette, aber ein Produkt darf sie casten, und
     * der Peppermint Manager tut das (`App\AttendeeStatus`). Bis zum
     * 29.10.2026 stand hier ein `in_array(..., true)` gegen drei Strings —
     * gegen ein Enum ist das IMMER falsch. Folge: In der Einzelansicht des
     * Managers stand hinter keinem Teilnehmer eine Antwort, auch wenn er
     * zugesagt hatte. Kein Fehler, keine leere Liste, nichts, was auffaellt.
     *
     * Gefunden beim Beheben von Bug #1110 („kein Zusage-Knopf") — dasselbe
     * Thema von der anderen Seite: Man konnte nicht antworten UND man sah die
     * Antworten nicht.
     *
     * `pending` faellt weiter heraus: „eingeladen und noch nicht geantwortet"
     * ist der Normalfall und sagt nichts.
     */
    private function antwort(mixed $status): ?string
    {
        $wert = $status instanceof \BackedEnum ? $status->value : $status;

        return in_array($wert, ['accepted', 'declined', 'tentative'], true) ? (string) $wert : null;
    }

    private function attendees(CalendarEvent $event): array
    {
        return $event->attendees()
            ->with('user')
            ->get()
            // KEIN Typ-Hinweis auf `CalendarEventAttendee`.
            //
            // Ein Produkt darf `attendees()` mit seinem EIGENEN Modell
            // ueberschreiben, und der Manager tut das (`App\Models\
            // CalendarEventAttendee`, das die Paketklasse nicht erweitert).
            // Mit dem Hinweis warf die Einzelansicht dort einen TypeError —
            // aber nur bei Terminen MIT Teilnehmern, weil die Abbildung sonst
            // nie laeuft. Genau deshalb ist es beim ersten Blick in den
            // Browser durchgerutscht: Der Probetermin hatte keine.
            //
            // Gelesen werden ohnehin nur Felder, die jedes Teilnehmermodell
            // hat. Ein Typ-Hinweis, der eine Klasse verlangt, die das Produkt
            // austauschen DARF, ist eine falsche Zusicherung.
            ->map(fn ($person) => [
                'name' => $person->user?->name
                    ?? $person->name
                    ?? $person->email
                    ?? '',
                // `pending` ist der Vorgabewert der Spalte und sagt nichts —
                // „eingeladen und noch nicht geantwortet" ist der Normalfall.
                // Angezeigt wird nur eine echte Antwort.
                'status' => $this->antwort($person->status),
                // Die Adresse zum Namen. Beim externen Gast ist sie haeufig
                // das Einzige, was es gibt — dann steht sie schon oben als
                // `name`, und die Darstellung laesst sie hier weg, statt sie
                // zweimal zu zeigen.
                'email' => $person->user?->email ?? $person->email,
            ])
            ->reject(fn (array $person) => $person['name'] === '')
            ->values()
            ->all();
    }
}
