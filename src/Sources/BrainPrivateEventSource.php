<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Peppermint\Calendar\Brain\KalenderFreigabe;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
use Peppermint\Calendar\Exceptions\ExternalSourceFailed;
use Throwable;

/**
 * Die PRIVATEN Termine der angemeldeten Person aus der Nabe.
 *
 * Sie werden angezeigt, nicht übernommen. Ein privater Termin gehört in die
 * Nabe; eine Kopie hier wäre eine zweite Wahrheit mit Abgleichbedarf. Deshalb
 * liefert diese Quelle {@see ExternalEvent} — keine Zeile in dieser Datenbank,
 * mit vorangestelltem Quellen-Schlüssel in der Id.
 *
 * Schreibbar ist sie trotzdem: anlegen, verschieben, löschen. Jeder dieser
 * Vorgänge reist hinüber und bringt die Fassung der Nabe zurück — auch beim
 * Anlegen. Was hier NICHT geht, ist das vollständige Ändern: Titel,
 * Teilnehmer, Serie. Dafür führt `ExternalEvent::$url` hinüber, denn die Maske
 * der Nabe trägt Felder, die ein Produktkalender nicht kennt.
 *
 * ## Nicht zu verwechseln mit {@see BrainCalendarSources}
 *
 * Das sind zwei verschiedene Dinge, und die Verwechslung kostet Zeit:
 *
 * | | |
 * |---|---|
 * | `BrainCalendarSources` | die Kalender der anderen PRODUKTE, über das Fähigkeits-Register (`calendar.events.list`) |
 * | `BrainPrivateEventSource` | die privaten Termine der PERSON, aus der Nabe selbst (`list-calendar-bundle-tool`) |
 *
 * ## Warum sie im Paket liegt
 *
 * Bis zum 27.10.2026 lag sie als `App\Calendar\AiBrainEventSource` **nur im
 * Peppermint Manager** — also keine dreifache Abschrift, sondern eine einzige
 * Fassung. Der Grund für den Umzug ist deshalb nicht Entdopplung, sondern das
 * Gegenteil: CRM und Verwaltung hatten die Funktion gar nicht, und ein
 * globales Kalenderpaket soll allen dasselbe geben. Wer sie im Produkt
 * nachbaute, baute nach, was ins Paket gehört (Paketvertrag, Wiki #359).
 *
 * ## Die Zuordnung läuft über die Identität, nicht über einen Parameter
 *
 * Die Nabe bekommt die E-Mail der angemeldeten Person im Kopf und entscheidet
 * selbst, was sie herausgibt. Hier wird nichts über Identitäten behauptet —
 * und `events()` weist einen fremden `$userId` ausdrücklich ab, damit auch ein
 * Administrator beim Kalender-Umschalten keinen fremden Privatkalender öffnet.
 */
class BrainPrivateEventSource extends WritableEventSource implements ErklaertAbwesenheit
{
    /**
     * Die Wache steckt in der Freigabe-Prüfung, nicht hier.
     *
     * Vorher nahm diese Klasse einen eigenen `$guard` und fragte selbst, wer
     * angemeldet ist — eine zweite Wahrheit neben
     * {@see KalenderFreigabe::personId()}. Aufgefallen ist es beim Schreiben
     * der Tests: Der Hinweis „nicht verbunden" blieb aus, weil die Quelle
     * `null` sah, wo die Prüfung längst eine Person kannte. Eine Frage, eine
     * Stelle.
     */
    public function __construct(
        private readonly KalenderFreigabe $freigabe,
    ) {}

    public function key(): string
    {
        return 'ai-brain';
    }

    public function label(): string
    {
        return (string) config('calendar.brain_private_events.label', 'AI Brain (privat)');
    }

    public function colour(): ?string
    {
        return config('calendar.brain_private_events.colour', '#8b5cf6');
    }

    /**
     * Angeboten wird sie nur, wenn die PERSON ihren Kalender freigeschaltet hat.
     *
     * ## Warum HIER und nicht in `events()`
     *
     * {@see EventSourceRegistry::available()} filtert mit dieser Methode, und
     * `collect()` läuft nur über diese Liste. Ein `false` entfernt die Quelle
     * damit aus BEIDEM — dem Abruf und dem Schalter in der Oberfläche. Eine
     * Prüfung in `events()` hätte den Aufruf gespart, aber einen Schalter
     * stehengelassen, der nichts anzeigt.
     *
     * Drei Bedingungen, in dieser Reihenfolge, weil jede die nächste erst
     * sinnvoll macht: Will das Produkt sie überhaupt, ist die Brücke da, und
     * hat diese Person sie freigeschaltet. Die dritte kostet einen Netzweg
     * (einmal je Minute je Person) — sie kommt deshalb zuletzt.
     */
    public function isAvailable(): bool
    {
        if (config('calendar.brain_private_events.enabled', true) !== true) {
            return false;
        }

        if (! $this->brueckeVorhanden()) {
            return false;
        }

        return $this->freigabe->fuerAngemeldetePerson();
    }

    /**
     * Warum sie fehlt — für die Oberfläche, nicht für das Log.
     *
     * Unterschieden werden zwei Fälle, weil sie zwei verschiedene Leser haben:
     *
     * - **Vom Produkt abgeschaltet oder Brücke fehlt** ⇒ `null`. Darüber muss
     *   niemand informiert werden, der es nicht ändern kann.
     * - **Alles da, aber die Person hat nicht freigeschaltet** ⇒ Hinweis. Das
     *   betrifft sie, und sie soll es wissen.
     *
     * Der Text schickt niemanden zum Selbst-Freischalten: Die Zuweisung liegt
     * in der Nabe hinter einem Verwaltungsrecht (`users/{user}/plugin-access`
     * hinter `MANAGE_SECRETS`). Jemandem einen Weg zu nennen, den er nicht
     * gehen darf, ist schlechter als ihm zu sagen, wen er fragen muss.
     *
     * @return array{titel: string, text: string}|null
     */
    public function abwesenheitsHinweis(): ?array
    {
        if (config('calendar.brain_private_events.enabled', true) !== true) {
            return null;
        }

        if (! $this->brueckeVorhanden()) {
            return null;
        }

        if ($this->freigabe->personId() === null) {
            return null;
        }

        if ($this->freigabe->fuerAngemeldetePerson()) {
            return null;
        }

        return [
            'titel' => 'AI-Brain-Kalender nicht verbunden',
            'text' => 'Deine privaten Termine aus AI Brain werden hier nicht angezeigt, '
                .'weil der Kalender für dein Konto dort nicht freigeschaltet ist. '
                .'Eine Administratorin oder ein Administrator kann das in AI Brain erledigen.',
        ];
    }

    /**
     * Ist die Bruecke zur Nabe installiert?
     *
     * Eigene Methode und nicht `class_exists()` an der Verwendungsstelle: Die
     * Bruecke steht in `suggest` und nicht in `require` (Regel 7) — in der
     * Testreihe DIESES Pakets ist sie deshalb nicht da, und ohne eine
     * ueberschreibbare Stelle waere jedes Verhalten dahinter unpruefbar.
     *
     * Eine Abhaengigkeit nur fuer die Tests zu erzwingen waere der falsche
     * Preis: Dann haengt sie in jeder Installation mit, damit eine Testreihe
     * laufen kann.
     */
    protected function brueckeVorhanden(): bool
    {
        return class_exists('\\Peppermint\\AiBrainBridge\\Facades\\AiBrain');
    }

    /**
     * @return array<int, ExternalEvent>
     */
    public function events(int $userId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        // Der Nutzer-Parameter wird bewusst NICHT weitergereicht. Die Nabe
        // liefert ausschliesslich die Termine der handelnden Person, und
        // handelnd ist, wer angemeldet ist. Einen fremden Kalender über diese
        // Quelle zu öffnen ist damit gar nicht erst möglich — auch nicht für
        // Administratoren beim Kalender-Umschalten (Manager Bug #657).
        if ((string) $userId !== (string) ($this->freigabe->personId() ?? '')) {
            return [];
        }

        $antwort = $this->termine(
            CarbonImmutable::parse($from)->toDateString(),
            CarbonImmutable::parse($to)->toDateString(),
        );

        if ($antwort['error'] !== null) {
            // Die Registrierung behandelt das als Ausfall: geloggt, weggelassen,
            // der übrige Kalender bleibt sichtbar. Eine nicht erreichbare Nabe
            // darf den Arbeitskalender nicht mitreissen.
            throw new \RuntimeException('AI Brain nicht erreichbar: '.$antwort['error']);
        }

        $zone = $antwort['timezone'] ?? config('app.timezone') ?: 'Europe/Berlin';
        $termine = [];

        foreach ($antwort['data'] as $zeile) {
            $termin = $this->toExternalEvent($zeile, $zone);

            if ($termin !== null) {
                $termine[] = $termin;
            }
        }

        return $termine;
    }

    /**
     * Die Termine holen und auf die Form bringen, die diese Klasse erwartet.
     *
     * Lag bis zum 27.10.2026 in `App\Services\AiBrainService` des Managers.
     * Die Form bleibt dieselbe — `['data' => …, 'timezone' => …, 'error' => …]`
     * —, damit der Umzug nichts stillschweigend anders macht.
     *
     * @return array{data: array<int, mixed>, timezone: string|null, error: string|null}
     */
    protected function termine(string $von, string $bis): array
    {
        try {
            $antwort = $this->ruf('list-calendar-bundle-tool', ['from' => $von, 'to' => $bis]);
        } catch (Throwable $fehler) {
            return ['data' => [], 'timezone' => null, 'error' => $fehler->getMessage()];
        }

        $termine = $antwort['events'] ?? null;
        $zone = $antwort['timezone'] ?? null;

        return [
            'data' => is_array($termine) ? $termine : [],
            // Die Nabe liefert Datum und Uhrzeit getrennt, in der Zone der
            // Person. Ohne sie läge jeder Termin um den Zonenversatz daneben.
            'timezone' => is_string($zone) && $zone !== '' ? $zone : null,
            'error' => null,
        ];
    }

    /**
     * Ein Werkzeug der Nabe rufen und die Nutzdaten herausgeben.
     *
     * @param  array<string, mixed>  $argumente
     * @return array<string, mixed>
     */
    protected function ruf(string $werkzeug, array $argumente = []): array
    {
        $bruecke = '\\Peppermint\\AiBrainBridge\\Facades\\AiBrain';

        if (! class_exists($bruecke)) {
            throw BrainBridgeMissing::forPrivateEvents();
        }

        $antwort = $bruecke::call($werkzeug, $argumente);

        if (($antwort['ok'] ?? false) !== true) {
            throw new \RuntimeException((string) ($antwort['error'] ?? 'AI Brain lieferte kein Ergebnis.'));
        }

        $daten = $antwort['data'] ?? [];

        return is_array($daten) ? $daten : [];
    }

    /**
     * @param  array<string, mixed>  $zeile
     */
    protected function toExternalEvent(array $zeile, string $zone): ?ExternalEvent
    {
        $datum = $zeile['date'] ?? null;

        if (! is_string($datum) || $datum === '') {
            return null;
        }

        $ganztaegig = (bool) ($zeile['all_day'] ?? false);

        $start = $ganztaegig || ! is_string($zeile['start'] ?? null)
            ? CarbonImmutable::parse($datum.' 00:00:00', $zone)
            : CarbonImmutable::parse($datum.' '.$zeile['start'], $zone);

        $ende = $ganztaegig || ! is_string($zeile['end'] ?? null)
            ? $start->endOfDay()
            : CarbonImmutable::parse($datum.' '.$zeile['end'], $zone);

        // Ein Termin über Mitternacht kommt mit einem Ende vor dem Anfang an;
        // ohne diese Korrektur zeichnete das Raster ihn rückwärts.
        if ($ende < $start) {
            $ende = $ende->addDay();
        }

        return new ExternalEvent(
            sourceKey: $this->key(),
            id: (string) ($zeile['occurrence_key'] ?? $zeile['id'] ?? md5($datum.($zeile['title'] ?? ''))),
            title: (string) ($zeile['title'] ?? 'Termin'),
            startsAt: $start,
            endsAt: $ende,
            allDay: $ganztaegig,
            location: is_string($zeile['location'] ?? null) ? $zeile['location'] : null,
        );
    }

    /**
     * Darf die angemeldete Person in der Nabe schreiben?
     *
     * Die Brücke trägt die handelnde Person mit und die Nabe entscheidet — hier
     * wird nichts über Identitäten behauptet. Geprüft wird nur, ob überhaupt
     * jemand angemeldet ist: Ein Hintergrundlauf soll nicht im Namen
     * irgendwessen drüben schreiben.
     */
    public function isWritable(): bool
    {
        return $this->isAvailable() && $this->freigabe->personId() !== null;
    }

    /**
     * Die Terminarten der Nabe.
     *
     * Kommt nichts zurück, bleibt die Liste leer und der Aufrufer verlässt sich
     * auf die Vorgabe drüben — raten wäre die schlechtere Antwort.
     *
     * @return array<int, ExternalKind>
     */
    public function kinds(): array
    {
        $antwort = $this->ruf('list-calendar-kinds-tool');

        return array_values(array_filter(array_map(static function ($zeile): ?ExternalKind {
            if (! is_array($zeile) || ! is_string($zeile['key'] ?? null)) {
                return null;
            }

            return new ExternalKind(
                $zeile['key'],
                (string) ($zeile['label'] ?? $zeile['key']),
                array_values(array_filter((array) ($zeile['requires'] ?? []), 'is_string')),
            );
        }, $antwort)));
    }

    public function create(int $userId, NewExternalEvent $event): ExternalEvent
    {
        $zone = $this->zone();

        $angelegt = $this->schreib('anlegen', 'add-calendar-tool', [
            'title' => $event->title,
            // Die Nabe nimmt Datum und Uhrzeit getrennt, in der Zone der
            // Person — dieselbe Form, in der sie sie auch herausgibt.
            'date' => $event->startsAt->setTimezone($zone)->toDateString(),
            'start' => $event->allDay ? null : $event->startsAt->setTimezone($zone)->format('H:i'),
            'end' => $event->allDay ? null : $event->endsAt->setTimezone($zone)->format('H:i'),
            'location' => $event->location,
            'description' => $event->description,
        ]);

        return $this->ausAntwort($angelegt, 'anlegen', $zone);
    }

    public function move(
        int $userId,
        string $eventId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?bool $allDay = null,
    ): ExternalEvent {
        $zone = $this->zone();

        $geaendert = $this->schreib('verschieben', 'update-calendar-tool', array_filter([
            'event_id' => $this->terminKennung($eventId, 'verschieben'),
            'starts_at' => $startsAt->setTimezone($zone)->format('Y-m-d H:i'),
            'ends_at' => $endsAt->setTimezone($zone)->format('Y-m-d H:i'),
            'all_day' => $allDay,
        ], static fn ($wert) => $wert !== null));

        return $this->ausAntwort($geaendert, 'verschieben', $zone);
    }

    public function delete(int $userId, string $eventId): void
    {
        $this->schreib('loeschen', 'delete-calendar-tool', [
            'event_id' => $this->terminKennung($eventId, 'loeschen'),
            'confirm' => true,
        ]);
    }

    /**
     * Die Kennung, mit der die Nabe etwas anfangen kann.
     *
     * Beim Lesen trägt ein Termin `occurrence_key ?? id` — bei einem Vorkommen
     * einer Serie also KEINE Terminkennung. Wer das ungeprüft weiterreicht,
     * bekommt drüben ein „nicht gefunden" und hält es für einen gelöschten
     * Termin. Deshalb hier eine klare Absage statt eines stillen Fehlschlags.
     */
    private function terminKennung(string $eventId, string $vorgang): int
    {
        if (! ctype_digit($eventId)) {
            throw new ExternalSourceFailed(
                'Dieser Termin gehört zu einer Serie im AI Brain. '
                .'Einzelne Vorkommen werden dort geändert — der Link steht am Termin.',
                $this->key(),
                $vorgang,
            );
        }

        return (int) $eventId;
    }

    /**
     * Ein schreibender Aufruf — er scheitert LAUT.
     *
     * Lesend fällt diese Quelle bei einem Ausfall einfach weg und der übrige
     * Kalender bleibt stehen. Schreibend wäre dasselbe Verhalten gefährlich:
     * Jemand glaubt, er habe einen Termin angelegt, und es gibt ihn nirgends.
     *
     * @param  array<string, mixed>  $argumente
     * @return array<string, mixed>
     */
    private function schreib(string $vorgang, string $werkzeug, array $argumente): array
    {
        if (! $this->isAvailable()) {
            throw ExternalSourceFailed::beim($vorgang, $this->key());
        }

        try {
            return $this->ruf($werkzeug, array_filter(
                $argumente,
                static fn ($wert) => $wert !== null,
            ));
        } catch (ExternalSourceFailed $durch) {
            throw $durch;
        } catch (Throwable $fehler) {
            throw new ExternalSourceFailed(
                'AI Brain nicht erreichbar: '.$fehler->getMessage(),
                $this->key(),
                $vorgang,
                $fehler,
            );
        }
    }

    /** @param array<string, mixed> $antwort */
    private function ausAntwort(array $antwort, string $vorgang, string $zone): ExternalEvent
    {
        // Die Fassung der Nabe, nicht die geschickte: Sie setzt ihre eigene
        // Kennung und kann Zeiten anpassen. Stünde hier die eigene, zeigte der
        // Kalender etwas anderes als drüben.
        $termin = $this->toExternalEvent($antwort, $zone);

        return $termin ?? throw new ExternalSourceFailed(
            'AI Brain hat den Vorgang ausgeführt, aber nichts Lesbares zurückgegeben.',
            $this->key(),
            $vorgang,
        );
    }

    private function zone(): string
    {
        return config('app.timezone') ?: 'Europe/Berlin';
    }

}
