<?php

namespace Peppermint\Calendar\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Calendar\Exceptions\ExternalSourceFailed;
use Peppermint\Calendar\Sources\EventSourceRegistry;
use Peppermint\Calendar\Sources\NewExternalEvent;
use Peppermint\Calendar\Sources\WritableEventSource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die vier Vorgaenge am Kalender eines ANDEREN Systems: dessen Terminarten
 * holen, dort anlegen, dort verschieben, dort loeschen.
 *
 * ## Warum das ins Paket gehoert
 *
 * Es ist Verdrahtung, und zwar in jeder Anwendung dieselbe: Quelle aus der
 * Registry holen, 404 wenn sie nicht beschreibbar ist, Eingaben pruefen, den
 * Vorgang ausfuehren, einen Fehlschlag als 502 beantworten. Vier Endpunkte, in
 * denen nichts steht, was ein Produkt von einem anderen unterscheidet — und
 * genau die Sorte Code, die laut Paketvertrag nicht dreimal geschrieben
 * werden soll.
 *
 * ## Was NICHT hier steht
 *
 * **Die Routen.** Sie werden nicht angemeldet, sondern vom Produkt gelegt
 * ({@see \Peppermint\Calendar\Http\CalendarRoutes}) — mit seinem Praefix und
 * vor allem mit SEINER Anmeldeschranke. Ein Paket, das eine Route selbst
 * registriert, entscheidet damit auch ueber ihre Absicherung, und das darf es
 * nicht.
 *
 * **Das Auflisten der Quellen.** Welche Quellen es gibt und wie sie in einer
 * Oberflaeche erscheinen (Farbe, Reihenfolge, Beschriftung), gehoert zur
 * Darstellung und bleibt im Produkt. Hier liegen nur die Vorgaenge.
 *
 * ## Der Unterschied zwischen 404 und 502
 *
 * **404**, wenn die Quelle nicht (mehr) beschreibbar ist. Das ist kein Fehler,
 * sondern die Antwort auf „nicht freigeschaltet": Verliert ein System das
 * Werkzeug oder die Freigabe, verschwindet es von selbst — und ein Aufruf, der
 * davor noch moeglich schien, findet eben nichts mehr.
 *
 * **502**, wenn der Vorgang drueben scheitert. Mit Quelle und Vorgang in der
 * Antwort, damit die Oberflaeche sagen kann, WELCHES System bei WELCHEM
 * Schritt nicht mitgespielt hat. „Etwas ist schiefgegangen" schickt den
 * naechsten Menschen auf die falsche Suche.
 */
class EventSourceController
{
    /**
     * Die Terminarten der fremden Quelle — auf Zuruf, nicht bei jedem Laden.
     *
     * Arten sind produkteigen, und sie zu holen ist ein Netzweg. Ihn bei jedem
     * Aufbau des Kalenders zu gehen hiesse, ihn fast immer umsonst zu gehen:
     * Gebraucht werden sie erst, wenn jemand ein Zielsystem auswaehlt.
     */
    public function kinds(string $source): JsonResponse
    {
        return response()->json([
            'kinds' => array_map(
                fn ($kind) => $kind->toArray(),
                $this->ziel($source)->kinds(),
            ),
        ]);
    }

    /**
     * Legt einen Termin im Kalender eines anderen Systems an.
     *
     * Er entsteht dort und bleibt dort — hier entsteht keine Zeile. Was
     * zurueckkommt, ist die Fassung des Zielsystems: Es rundet Zeiten,
     * ergaenzt Vorgaben und haengt seine Kennung an. Die eigene zu zeigen
     * hiesse, im Kalender etwas anderes stehen zu haben als drueben.
     */
    public function store(Request $request, string $source): JsonResponse
    {
        $ziel = $this->ziel($source);

        $daten = $request->validate([
            'kind' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:200'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Die Felder, die eine ART des Zielsystems verlangt — nicht jede
            // tut das. `meeting_digital` im Manager meldet `meeting_url` ueber
            // `requires`, und ohne dieses Feld hier bliebe die Angabe auf dem
            // Weg liegen: Die Maske fragte danach, das Zielsystem lehnte ab,
            // und die Meldung handelte von etwas, das der Mensch eingetragen
            // zu haben glaubte.
            'meeting_url' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->fremderVorgang(fn () => response()->json([
            'event' => $ziel->create($this->person($request), new NewExternalEvent(
                kind: $daten['kind'],
                title: $daten['title'],
                startsAt: CarbonImmutable::parse($daten['starts_at']),
                endsAt: CarbonImmutable::parse($daten['ends_at']),
                allDay: (bool) ($daten['all_day'] ?? false),
                location: $daten['location'] ?? null,
                description: $daten['description'] ?? null,
                // In den Beutel, nicht als eigener Parameter: Was eine Art
                // braucht, ist Sache des Zielsystems. `toArray()` mischt ihn
                // flach ein, damit er drueben dort ankommt, wo gelesen wird.
                extra: array_filter([
                    'meeting_url' => $daten['meeting_url'] ?? null,
                ], static fn ($wert) => $wert !== null && $wert !== ''),
            ))->toArray(),
        ], 201));
    }

    /**
     * Verschiebt einen Termin im anderen System — neuer Anfang, neues Ende.
     *
     * Der Vorgang beim Ziehen im Raster, und bewusst ein eigener: Wer eine
     * Stunde nach hinten zieht, will nicht, dass dabei Titel, Teilnehmer und
     * Serie mitgeschickt werden — schon gar nicht in der Fassung, die diese
     * Anwendung gerade zufaellig im Speicher hat.
     */
    public function move(Request $request, string $source, string $event): JsonResponse
    {
        $ziel = $this->ziel($source);

        $daten = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
        ]);

        return $this->fremderVorgang(fn () => response()->json([
            'event' => $ziel->move(
                $this->person($request),
                $event,
                CarbonImmutable::parse($daten['starts_at']),
                CarbonImmutable::parse($daten['ends_at']),
                // Nur mitschicken, wenn es sich aendert. `array_key_exists`
                // und nicht `?? null`: Ein ausdrueckliches `false` ist eine
                // Angabe, und es zu verlieren machte beim Verschieben aus
                // jedem Ganztagstermin einen Zeittermin.
                array_key_exists('all_day', $daten) ? (bool) $daten['all_day'] : null,
            )->toArray(),
        ]));
    }

    /** Loescht einen Termin im anderen System. */
    public function destroy(Request $request, string $source, string $event): JsonResponse
    {
        $ziel = $this->ziel($source);

        return $this->fremderVorgang(function () use ($ziel, $request, $event) {
            $ziel->delete($this->person($request), $event);

            return response()->json(['deleted' => true]);
        });
    }

    /**
     * Die beschreibbare Quelle — oder 404.
     *
     * Aus `writable()` und nicht aus `available()`: Eine Quelle, die man nur
     * ansehen darf, hat die Schreibmethoden gar nicht. Sie hier zu finden und
     * dann am fehlenden Verfahren zu scheitern waere ein 500, wo ein 404
     * richtig ist.
     */
    protected function ziel(string $source): WritableEventSource
    {
        return app(EventSourceRegistry::class)->writable()[$source] ?? throw new NotFoundHttpException;
    }

    /**
     * Die handelnde Person.
     *
     * Ueber `getAuthIdentifier()` und nicht ueber `->id`: Das Paket laesst
     * Anwendungen ihre eigenen Spaltennamen behalten, und ein Produkt mit
     * einem anders benannten Schluessel bekaeme sonst `null` — stillschweigend,
     * und damit die Termine der falschen (oder keiner) Person.
     */
    protected function person(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }

    /**
     * Ein Fehlschlag drueben wird 502, mit Quelle und Vorgang.
     *
     * Nicht 500: Diese Anwendung ist in Ordnung, die andere hat nicht
     * mitgespielt. Und nicht stillschweigend ein Erfolg — beim Schreiben ist
     * das der schlimmste Ausgang, weil die Oberflaeche die Zeile dann schon
     * gezeichnet hat und es den Termin nirgends gibt.
     */
    protected function fremderVorgang(callable $vorgang): JsonResponse
    {
        try {
            return $vorgang();
        } catch (ExternalSourceFailed $gescheitert) {
            return response()->json([
                'message' => $gescheitert->getMessage(),
                'source' => $gescheitert->sourceKey,
                'operation' => $gescheitert->vorgang,
            ], 502);
        }
    }
}
