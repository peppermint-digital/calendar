<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Brain\KalenderFreigabe;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
use RuntimeException;
use Throwable;

/**
 * Die abonnierten fremden Kalender der angemeldeten Person — aus der Nabe.
 *
 * ## Warum es diese Quelle gibt
 *
 * Seit dem 29.10.2026 liegen die Abos (Outlook, Google, jede ICS-Adresse) in
 * AI Brain und nicht mehr im Peppermint Manager. Bastian, wörtlich: *„Das
 * sollte gar nicht mehr im Manager verfügbar sein."* Die Begründung ist
 * dieselbe wie bei den privaten Terminen im September (#3529, #4823): Ein Abo
 * gehört dem MENSCHEN und nicht einem Produkt.
 *
 * Damit entsteht aber eine Lücke, die sichtbarer wäre als alles andere: Im
 * Manager standen 1009 kommende Abo-Termine im Arbeitskalender. Wandern die
 * Daten, ohne dass die Anzeige mitwandert, verschwinden sie dort. Diese Quelle
 * ist die Anzeige — Geschwister von {@see BrainPrivateEventSource}, nach
 * demselben Muster und mit derselben Identitätsregel.
 *
 * ## Nur lesend, und das ist keine Einschränkung
 *
 * Ein Abo-Termin wird in einem fremden System geändert, nicht hier und nicht in
 * der Nabe. Eine schreibbare Quelle würde eine Änderung anbieten, die der
 * nächste Abgleich stillschweigend zurücknimmt — das ist schlimmer als keine
 * Änderung. Deshalb {@see EventSource} und nicht {@see WritableEventSource}.
 *
 * ## Die Zuordnung läuft über die Identität, nicht über einen Parameter
 *
 * Die Nabe bekommt die E-Mail der angemeldeten Person in der Kopfzeile und
 * entscheidet selbst, was sie herausgibt. `events()` weist einen fremden
 * `$userId` ausdrücklich ab — damit auch ein Administrator beim
 * Kalender-Umschalten keine fremden Abo-Termine sieht. Das ist hier nicht
 * Formsache: In einem abonnierten Privatkalender steht, was jemand privat
 * vorhat.
 */
class BrainSubscribedEventSource extends EventSource
{
    public function __construct(
        private readonly KalenderFreigabe $freigabe,
    ) {}

    public function key(): string
    {
        return 'ai-brain-abos';
    }

    public function label(): string
    {
        return (string) config('calendar.brain_subscriptions.label', 'Abonnierte Kalender');
    }

    public function colour(): ?string
    {
        return config('calendar.brain_subscriptions.colour', '#0ea5e9');
    }

    /**
     * Angeboten wird sie nur, wenn die PERSON ihren Kalender freigeschaltet hat.
     *
     * Dieselbe Reihenfolge wie bei den privaten Terminen, und aus demselben
     * Grund: {@see EventSourceRegistry::available()} filtert damit, und ein
     * `false` nimmt die Quelle aus dem Abruf UND aus dem Schalter in der
     * Oberfläche. Ein Schalter, der nichts anzeigt, ist schlimmer als keiner.
     *
     * Die Freigabe ist dieselbe wie für die privaten Termine: Wer in der Nabe
     * den Kalender freigeschaltet hat, hat ihn freigeschaltet. Eine zweite,
     * eigene Freigabe nur für Abos wäre ein zweiter Schalter für dieselbe
     * Frage — und die Hälfte der Menschen fände den zweiten nie.
     */
    public function isAvailable(): bool
    {
        if (config('calendar.brain_subscriptions.enabled', true) !== true) {
            return false;
        }

        if (! $this->brueckeVorhanden()) {
            return false;
        }

        return $this->freigabe->fuerAngemeldetePerson();
    }

    /**
     * @return array<int, ExternalEvent>
     */
    public function events(int $userId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        // Siehe Klassenkommentar: Der Parameter wird NICHT weitergereicht.
        if ((string) $userId !== (string) ($this->freigabe->personId() ?? '')) {
            return [];
        }

        try {
            $antwort = $this->ruf('list-subscribed-events-tool', [
                'from' => CarbonImmutable::parse($from)->toDateString(),
                'to' => CarbonImmutable::parse($to)->toDateString(),
            ]);
        } catch (Throwable $fehler) {
            // Die Registrierung behandelt das als Ausfall: geloggt,
            // weggelassen, der übrige Kalender bleibt sichtbar. Eine nicht
            // erreichbare Nabe darf den Arbeitskalender nicht mitreissen.
            throw new RuntimeException('AI Brain nicht erreichbar: '.$fehler->getMessage());
        }

        $zone = $antwort['timezone'] ?? config('app.timezone') ?: 'Europe/Berlin';
        $termine = [];

        foreach ((array) ($antwort['events'] ?? []) as $zeile) {
            if (! is_array($zeile)) {
                continue;
            }

            $termin = $this->toExternalEvent($zeile, (string) $zone);

            if ($termin !== null) {
                $termine[] = $termin;
            }
        }

        return $termine;
    }

    /**
     * @param  array<string, mixed>  $zeile
     */
    protected function toExternalEvent(array $zeile, string $zone): ?ExternalEvent
    {
        $start = $zeile['starts_at'] ?? null;

        if (! is_string($start) || $start === '') {
            return null;
        }

        $beginn = CarbonImmutable::parse($start)->setTimezone($zone);
        $ganztaegig = (bool) ($zeile['all_day'] ?? false);

        $ende = is_string($zeile['ends_at'] ?? null) && $zeile['ends_at'] !== ''
            ? CarbonImmutable::parse($zeile['ends_at'])->setTimezone($zone)
            : ($ganztaegig ? $beginn->endOfDay() : $beginn->addHour());

        // Ein Termin, dessen Ende vor dem Anfang liegt, kommt vor — ein
        // Ganztagstermin mit ausschliessendem Ende etwa, falls ein Abgleich ihn
        // je so ablegt. Ohne diese Zeile zeichnete das Raster ihn rückwärts.
        if ($ende < $beginn) {
            $ende = $beginn->endOfDay();
        }

        return new ExternalEvent(
            sourceKey: $this->key(),
            id: (string) ($zeile['id'] ?? $zeile['uid'] ?? md5($start.($zeile['title'] ?? ''))),
            title: (string) ($zeile['title'] ?? 'Termin'),
            startsAt: $beginn,
            endsAt: $ende,
            allDay: $ganztaegig,
            location: is_string($zeile['location'] ?? null) ? $zeile['location'] : null,
            // Welches Abo — damit die Oberfläche „Outlook" neben den Termin
            // schreiben kann, statt alle Abos in einen Topf zu werfen. Als
            // `extra`, weil es Anzeige ist und kein Kernfeld (Vertrag, Ring 3).
            //
            // NUR wenn die Nabe es nennt: `['subscription' => null]` wäre ein
            // nicht-leeres `extra` und würde den Schlüssel in jede Antwort
            // schreiben. Der Kommentar an `ExternalEvent::toArray()` sagt,
            // warum das schlecht ist — „ein Schlüssel, der immer da und meist
            // leer ist, lehrt den Leser, ihn zu übersehen".
            extra: is_string($zeile['subscription'] ?? null)
                ? ['subscription' => $zeile['subscription']]
                : [],
        );
    }

    /**
     * Ist die Bruecke EINGERICHTET — nicht bloss installiert?
     *
     * `class_exists` auf die Fassade waere die falsche Frage: AI Brain hat das
     * Paket (fuer die Umschaltleiste), ohne es einzurichten, und der erste
     * Aufruf wirft dort `BindingResolutionException`. Das Bruecken-Paket
     * beantwortet die Frage selbst, indem es den Manager aufzuloesen versucht.
     *
     * Praktisch spielt es fuer DIESE Quelle keine Rolle — das Brain
     * registriert keine Quellen, es ist die Nabe. Richtig ist es trotzdem, und
     * die naechste Kopie dieser Zeile landet vielleicht woanders.
     */
    protected function brueckeVorhanden(): bool
    {
        $pruefung = '\\Peppermint\\AiBrainBridge\\Verbund\\Bruecke';

        return class_exists($pruefung) && $pruefung::eingerichtet();
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
            throw new RuntimeException((string) ($antwort['error'] ?? 'AI Brain lieferte kein Ergebnis.'));
        }

        $daten = $antwort['data'] ?? [];

        return is_array($daten) ? $daten : [];
    }
}
