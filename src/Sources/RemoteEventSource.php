<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Peppermint\Calendar\Exceptions\ExternalSourceFailed;
use RuntimeException;

/**
 * Der Kalender eines anderen Systems — angezeigt und bepflegt, nie uebernommen.
 *
 * ## Was hier steht und was nicht
 *
 * Hier steht die Mechanik: fragen, Antworten abbilden, Fehlschlaege
 * unterscheiden, die Person aufloesen, die Zusatzfelder durchreichen. Sie ist
 * in jeder Anwendung dieselbe. Der WEG zum anderen System steht nicht hier,
 * sondern hinter {@see RemoteCalendar} — AI Brain ruft seine angebundenen
 * Produkte ueber den eigenen Gateway, ein Produkt ueber die Bridge.
 *
 * Diese Klasse kennt deshalb **kein einzelnes System**. Sie weiss nicht, dass
 * es einen Manager gibt, und kennt keinen seiner Feldnamen. Sobald sie einen
 * kennte, waere sie wieder der Sonderclient, den die gemeinsame Fassung
 * abgeloest hat.
 *
 * ## Kein Abgleich, keine Kopie
 *
 * Die Termine gehoeren weiterhin dem anderen System. Eine Kopie hier waere
 * eine zweite Wahrheit mit Abgleichbedarf, und die Frage, welche gewinnt,
 * haette keine gute Antwort. Was zurueckkommt, ist ein {@see ExternalEvent} —
 * ohne Zeile in dieser Datenbank.
 *
 * ## Lesen schweigt, Schreiben schreit
 *
 * Ein Ausfall beim Lesen wirft eine `RuntimeException`; die Registrierung
 * protokolliert sie und laesst die Quelle weg, der Rest des Kalenders bleibt
 * sichtbar. Ein Ausfall beim Schreiben wirft {@see ExternalSourceFailed} und
 * muss bis zur Oberflaeche durchschlagen: Zwischen „Seite geoeffnet" und
 * „Speichern gedrueckt" liegen Minuten, und ein Termin, den jemand angelegt zu
 * haben glaubt und den es nirgends gibt, ist schlimmer als eine Fehlermeldung.
 */
class RemoteEventSource extends WritableEventSource
{
    public function __construct(protected readonly RemoteCalendar $remote) {}

    public function key(): string
    {
        return $this->remote->key();
    }

    public function label(): string
    {
        return $this->remote->label();
    }

    /**
     * Reine Darstellung, und deshalb Sache der Anwendung: Die Farbe gehoert der
     * Oberflaeche, nicht dem Kommunikationslayer. Fehlt ein Eintrag, faellt die
     * Anzeige auf ihre eigene Vorgabe zurueck.
     */
    public function colour(): ?string
    {
        $farbe = config('calendar.source_colours.'.$this->key());

        return is_string($farbe) ? $farbe : null;
    }

    /**
     * Verfuegbar ist die Quelle, wenn das System seine Termine ueberhaupt
     * herausgibt. Verliert es das Werkzeug, verschwindet die Quelle von selbst
     * — das ist die Antwort auf „nicht freigeschaltet", und sie braucht keine
     * zweite Pflegestelle.
     */
    public function isAvailable(): bool
    {
        return $this->remote->offers('calendar.events.list');
    }

    /**
     * Drei Faehigkeiten und ein Recht, nicht eines von beidem.
     *
     * Ein System, das nur anlegen laesst, waere beschreibbar fuer einen Knopf
     * und nicht fuer die anderen zwei — und die Oberflaeche muesste das je
     * Knopf erneut pruefen. Genau die Abfrage vergisst jemand.
     */
    public function isWritable(): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        foreach (['calendar.events.create', 'calendar.events.update', 'calendar.events.delete'] as $faehigkeit) {
            if (! $this->remote->offers($faehigkeit)) {
                return false;
            }
        }

        $person = $this->currentUserId();

        // Fail-closed ohne angemeldete Person — und hier, nicht erst beim
        // Erfueller. Wer eine 0 weiterreicht, verlaesst sich darauf, dass jede
        // Umsetzung des Vertrags sie als „niemand" liest; eine liest sie als
        // Benutzernummer.
        return $person !== null && $this->remote->mayWrite($person);
    }

    /**
     * Die Terminarten des anderen Systems.
     *
     * Arten sind produkteigen — dieses System kennt weder `business` noch
     * `meeting_digital`. Bietet das andere den Vorgang nicht an, ist die Liste
     * leer und der Aufrufer muss sich auf dessen Vorgabe verlassen. Raten waere
     * die schlechtere Antwort: Eine falsche Art legt den Termin in die falsche
     * Schublade, und das faellt erst drueben auf.
     *
     * @return array<int, ExternalKind>
     */
    public function kinds(): array
    {
        if (! $this->remote->offers('calendar.kinds.list')) {
            return [];
        }

        $ergebnis = $this->remote->call('calendar.kinds.list');

        if (! $ergebnis->ok) {
            return [];
        }

        $arten = [];

        foreach ($ergebnis->rows() as $zeile) {
            if (! isset($zeile['key'])) {
                continue;
            }

            // `creatable` entscheidet das ANBIETENDE System: Die
            // Ticket-Zeitblock-Art der Verwaltung entsteht aus einem Ticket und
            // ist von Hand nicht anlegbar. Wer sie trotzdem anbietet, baut eine
            // Auswahl, die beim Speichern abgelehnt wird.
            if (array_key_exists('creatable', $zeile) && ! $zeile['creatable']) {
                continue;
            }

            $arten[] = new ExternalKind(
                key: (string) $zeile['key'],
                label: (string) ($zeile['label'] ?? $zeile['kind_label'] ?? $zeile['key']),
                requires: array_values(array_filter((array) ($zeile['requires'] ?? []), 'is_string')),
            );
        }

        return $arten;
    }

    /**
     * @return array<int, ExternalEvent>
     */
    public function events(int $userId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $kennung = $this->remote->identify($userId);

        // Ohne aufloesbare Person keine fremden Termine — fail-closed. Ein
        // Kalender, der bei unbekannter Identitaet den ganzen Team-Kalender
        // zeigt, war Bug #657.
        if ($kennung === null) {
            return [];
        }

        $ergebnis = $this->remote->call('calendar.events.list', [
            'from' => CarbonImmutable::parse($from)->toDateString(),
            'to' => CarbonImmutable::parse($to)->toDateString(),
        ]);

        if (! $ergebnis->ok) {
            // Ein Ausfall ist kein leeres Ergebnis. Die Registrierung behandelt
            // den Wurf als Ausfall: protokolliert, weggelassen, der Rest des
            // Kalenders bleibt sichtbar.
            throw new RuntimeException($this->label().' nicht erreichbar: '.$ergebnis->message);
        }

        $termine = [];

        foreach ($ergebnis->rows() as $zeile) {
            $termin = $this->toExternalEvent($zeile, $kennung);

            if ($termin !== null) {
                $termine[] = $termin;
            }
        }

        return $termine;
    }

    public function create(int $userId, NewExternalEvent $event): ExternalEvent
    {
        $kennung = $this->person($userId, 'anlegen');

        $ergebnis = $this->remote->call('calendar.events.create', $event->toArray());

        $this->durchgefallen($ergebnis, 'anlegen');

        // Die Antwort des Zielsystems, nicht das Geschickte: Es rundet Zeiten,
        // ergaenzt Vorgaben und haengt seine Kennung an. Waere hier die eigene
        // Fassung zu sehen, stuende im Kalender etwas anderes als drueben.
        return $this->toExternalEvent((array) ($ergebnis->data ?? []), $kennung)
            ?? throw new ExternalSourceFailed(
                $this->label().' hat den Termin angelegt, aber nichts Lesbares zurueckgegeben.',
                $this->key(),
                'anlegen',
            );
    }

    public function move(
        int $userId,
        string $eventId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?bool $allDay = null,
    ): ExternalEvent {
        $kennung = $this->person($userId, 'verschieben');

        $ergebnis = $this->remote->call('calendar.events.update', array_filter([
            'calendar_event_id' => $eventId,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            // Nur mitschicken, wenn es sich aendert. Wer hier immer `false`
            // sendete, machte aus jedem Ganztagstermin beim Verschieben einen
            // Zeittermin.
            'all_day' => $allDay,
        ], static fn ($wert) => $wert !== null));

        $this->durchgefallen($ergebnis, 'verschieben');

        // Anders als beim Anlegen ist die Antwort hier nicht bloss Beiwerk: Das
        // Raster darf die neue Position erst behalten, wenn das Zielsystem sie
        // bestaetigt hat. Kommt nichts Lesbares zurueck, ist das ein Fehlschlag
        // und keine Gelegenheit, die gezogene Fassung zu zeigen.
        return $this->toExternalEvent((array) ($ergebnis->data ?? []), $kennung)
            ?? throw new ExternalSourceFailed(
                $this->label().' hat den Termin verschoben, aber nichts Lesbares zurueckgegeben.',
                $this->key(),
                'verschieben',
            );
    }

    public function delete(int $userId, string $eventId): void
    {
        $this->person($userId, 'loeschen');

        $ergebnis = $this->remote->call('calendar.events.delete', [
            'calendar_event_id' => $eventId,
            // Ohne das behandeln loeschende Werkzeuge den Aufruf als Vorschau
            // und melden Erfolg, ohne etwas zu tun.
            'confirm' => true,
        ]);

        $this->durchgefallen($ergebnis, 'loeschen');
    }

    /**
     * Die Person drueben — oder der Vorgang findet nicht statt.
     *
     * An einer Stelle, damit der Riegel nicht je Vorgang neu geschrieben wird:
     * Ein Eingriff im fremden System unter einer geratenen Identitaet ist
     * schlimmer als einer, der ausbleibt.
     */
    protected function person(int $userId, string $vorgang): string
    {
        return $this->remote->identify($userId) ?? throw ExternalSourceFailed::beim($vorgang, $this->key());
    }

    /** Ein Fehlschlag beim Schreiben schlaegt durch, mit dem Grund der Gegenstelle. */
    protected function durchgefallen(RemoteResult $ergebnis, string $vorgang): void
    {
        if ($ergebnis->ok) {
            return;
        }

        throw new ExternalSourceFailed(
            $this->label().' nicht erreichbar: '.$ergebnis->message,
            $this->key(),
            $vorgang,
        );
    }

    /**
     * Die angemeldete Person, soweit die Anwendung eine kennt.
     *
     * Ueber `auth()` und nicht ueber einen Parameter, weil `isWritable()` aus
     * dem Vertrag der Oberflaeche kommt und keinen traegt. Fehlt die Anmeldung,
     * faellt das Schreiben aus — ein Hintergrundlauf soll nicht im Namen
     * irgendwessen in fremde Systeme schreiben.
     */
    protected function currentUserId(): ?int
    {
        $id = function_exists('auth') ? auth()->id() : null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Sprungziel im anderen System.
     *
     * Erst die Adresse aus der Antwort — dorthin gehoert sie, das Produkt kennt
     * seine eigenen Wege. Nur solange ein Werkzeug sie nicht mitliefert, wird
     * sie aus der Wurzeladresse und einem Pfadmuster gebaut. Fehlt beides,
     * bleibt der Termin ohne Sprungziel; das ist besser als ein geratener Link,
     * der beim Manager auf `/tasks/{id}` zeigte und fuer die Verwaltung
     * (`/tickets/{id}`) falsch gewesen waere.
     *
     * @param  array<string, mixed>  $zeile
     */
    protected function url(array $zeile): ?string
    {
        if (isset($zeile['url']) && is_string($zeile['url']) && $zeile['url'] !== '') {
            return $zeile['url'];
        }

        $muster = config('calendar.source_event_paths.'.$this->key());
        $wurzel = $this->remote->baseUrl();

        if (! is_string($muster) || $wurzel === null || ! isset($zeile['id'])) {
            return null;
        }

        return rtrim($wurzel, '/').str_replace('{id}', (string) $zeile['id'], $muster);
    }

    /**
     * Was das andere System zu seinem Termin zu sagen hat, ausser den Feldern,
     * die jeder Kalender teilt: Projekt, Kunde, Abrechnung, Teilnehmer.
     *
     * Bewusst OHNE Kenntnis des Produkts: alles, was nicht schon in ein
     * Kernfeld geflossen ist, wandert weiter. Was ankommt, bestimmt damit das
     * andere System ueber seine eigene Antwort — und das ist die richtige
     * Stelle dafuer.
     *
     * Angezeigt, nie gespeichert. Kollidierende Schluessel wirft der
     * {@see ExternalEvent} selbst weg.
     *
     * @param  array<string, mixed>  $zeile
     * @return array<string, mixed>
     */
    protected function extra(array $zeile): array
    {
        // Die Aliasse mit heraus, nicht nur die Kernnamen: Der Manager hat sein
        // Antwortformat einmal umgestellt und schickt je nach Stand `start`
        // oder `starts_at`. Ohne diese Zeile stuende die Zeit doppelt drin —
        // einmal als Kernfeld, einmal als vermeintliche Zusatzinformation.
        $verbraucht = [
            'id', 'title', 'location', 'url',
            'starts_at', 'ends_at', 'start', 'end',
            'all_day', 'user_email', 'user',
        ];

        return array_filter(
            array_diff_key($zeile, array_flip($verbraucht)),
            static fn ($wert) => $wert !== null && $wert !== '' && $wert !== [],
        );
    }

    /**
     * @param  array<string, mixed>  $zeile
     */
    protected function toExternalEvent(array $zeile, string $kennung): ?ExternalEvent
    {
        // Beide Formen werden gelesen, damit ein Deploy auf der einen Seite
        // nicht die andere blind macht.
        $start = $zeile['starts_at'] ?? $zeile['start'] ?? null;
        $ende = $zeile['ends_at'] ?? $zeile['end'] ?? null;

        if ($start === null) {
            return null;
        }

        // Zweiter Riegel neben dem des Werkzeugs: Was nicht dieser Person
        // gehoert, wird nicht angezeigt. Fehlt die Angabe, bleibt der Termin
        // draussen — im Zweifel weniger zeigen.
        $eigner = $zeile['user_email'] ?? ($zeile['user']['email'] ?? null);

        if ($eigner !== null && strcasecmp((string) $eigner, $kennung) !== 0) {
            return null;
        }

        $beginn = CarbonImmutable::parse($start);
        $schluss = $ende !== null ? CarbonImmutable::parse($ende) : $beginn->addHour();

        return new ExternalEvent(
            sourceKey: $this->key(),
            id: (string) ($zeile['id'] ?? md5($beginn->toIso8601String().($zeile['title'] ?? ''))),
            title: (string) ($zeile['title'] ?? 'Termin'),
            startsAt: $beginn,
            endsAt: $schluss,
            allDay: (bool) ($zeile['all_day'] ?? false),
            location: $zeile['location'] ?? null,
            url: $this->url($zeile),
            extra: $this->extra($zeile),
        );
    }
}
