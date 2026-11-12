<?php

namespace Peppermint\Calendar\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Einen ICS-Feed holen und in Termine zerlegen.
 *
 * ## Warum das im Paket liegt
 *
 * Weil es in jedem Produkt gleich aussaehe. Es stand bis zum 29.10.2026 als
 * `App\Services\IcsParserService` im Peppermint Manager — 207 Zeilen, die kein
 * anderes System hatte, obwohl ein Outlook-Feed ueberall derselbe ist.
 *
 * ## Was hier bewusst NICHT passiert
 *
 * Speichern. Diese Klasse holt und wertet aus; was mit dem Ergebnis geschieht,
 * entscheidet {@see SubscriptionSync}. Die alte Fassung tat beides in einer
 * Methode, und genau daran hing ihr Fehler (siehe dort).
 *
 * ## `sabre/vobject` ist ein `suggest`
 *
 * Regel 7 des Paketvertrags: Wer keine Abos fuehrt, soll keinen
 * iCalendar-Auswerter mitinstallieren muessen. Ohne das Paket bricht diese
 * Klasse LAUT ab, statt eine leere Liste zu liefern — eine leere Liste saehe
 * aus wie „der Feed hat keine Termine", und der Abgleich wuerde daraufhin alles
 * wegraeumen.
 *
 * ## Das `Http::` hier ist RICHTIG und bleibt — es ist die Aussenwelt
 *
 * Wer den Komm-Layer aufraeumt, kommt an dieser Zeile vorbei und sieht einen
 * direkten Netzaufruf, also scheinbar einen Rest aus der Zeit vor dem
 * Verbund. Der Satz steht deshalb hier und nicht nur im Wiki: Der Leser kommt
 * am Code vorbei, nicht am Artikel.
 *
 * **Die Grenze ist nicht „direkter Aufruf gegen Vorgang", sondern WER am
 * anderen Ende sitzt:**
 *
 * | Gegenstelle | Weg |
 * |---|---|
 * | ein anderes Peppermint-PRODUKT | `AiBrain::gateway('<vorgang>', …, $slug)` |
 * | die NABE selbst (AI Brain) | `AiBrain::call('<werkzeug>')` — der Nabe-Zweig |
 * | **die Aussenwelt** (hier) | **direkt, wie hier** |
 *
 * Diese Klasse holt den ICS-Feed eines FREMDEN Anbieters — Google, Outlook,
 * ein Vereinskalender. Der Komm-Layer ist der Weg zwischen UNSEREN Systemen
 * (Produkt-Vertrag, Regel 2). Google steht in keinem Faehigkeits-Register,
 * kennt keinen Vorgangsnamen und hat keine Freigabe in
 * `comm_layer.gateway_grants`. Ein Umbau haette hier kein Ziel: Der Gateway
 * loest jedes Ziel ueber ein `ConnectedProduct` mit `mcp_url` auf, und ein
 * fremder Anbieter ist keines. Der Aufruf fiele mit `not_offered` aus — und
 * ein Abo, das deshalb leer zurueckkommt, laesst den Abgleich den ganzen
 * Kalender wegraeumen (siehe `holen()`).
 *
 * **Die Prueffrage, wenn der naechste hier steht:** Steht die Gegenstelle in
 * `config('ai-brain.comm_layer.capability_map')`? Nein → sie gehoert nicht in
 * den Komm-Layer, und zwar nicht aus Bequemlichkeit, sondern weil es dort
 * keinen Weg zu ihr gibt.
 *
 * ## Dass die NABE kein Gateway-Ziel ist, steht woertlich im Postfach-Paket
 *
 * Und zwar besser, als es hier noch einmal stuende — deshalb der Verweis statt
 * einer zweiten Fassung. `peppermint/mailbox`
 * `src/MailboxServiceProvider.php`, Methode `mitteRufen()`, Abschnitt
 * „`call()` und ausdruecklich NICHT der Gateway":
 *
 * > „Der Gateway leitet an ein angebundenes PRODUKT weiter. AI Brain ist
 * > keines — es ist die Nabe und steht in keinem Faehigkeits-Register.
 * > `gateway(..., 'ai-brain')` haette also nie ein Ziel gefunden, und der
 * > Ausfall waere als ‚Mitte nicht erreichbar' durchgegangen: Jede
 * > Zustaendigkeit haette gefehlt, ohne dass etwas bricht."
 *
 * Umgesetzt wird es in `peppermint/mailbox`
 * `src/Werkzeuge/BridgeWerkzeugquelle.php` als Verzweigung: Nabe → `call()`,
 * Produkt → `gateway()`. Die Pakete `inbox` und `notifications` machen es
 * gleich.
 *
 * **Warum das HIER steht, obwohl es die Aussenwelt betrifft:** Weil es die
 * zweite Haelfte derselben Frage ist. „Nicht ueber den Gateway" hat zwei
 * verschiedene Gruende — die Gegenstelle ist draussen (hier), oder sie ist die
 * Mitte selbst (dort). Wer nur den einen kennt, stellt beim naechsten Mal den
 * anderen falsch um. Gemessen am 12.11.2026: Von den drei Stellen, die ein
 * Auftrag als „Aussenwelt" fuehrte, waren ZWEI in Wahrheit die Nabe.
 *
 * ## Die drei Schwesterstellen — und KEINE zwei davon aus demselben Grund
 *
 * Das ist der Teil, den man leicht falsch zusammenfasst. Jede der drei
 * umgeht den Komm-Layer, und jede aus einem ANDEREN Grund. Eine gemeinsame
 * Regel waere an zwei von drei Stellen falsch:
 *
 * | Stelle | Gegenstelle | warum nicht ueber den Komm-Layer | Prueffrage |
 * |---|---|---|---|
 * | **hier** | fremder ICS-Anbieter | Aussenwelt, steht in keinem Register | steht sie in der `capability_map`? |
 * | `mailbox` `src/Jmap/JmapClient.php` | fremder Mailserver | dasselbe | dieselbe |
 * | `mailbox` `src/Schreiben/BrueckenDiktat.php` | **die NABE** (`/api/v1/transcribe`) | eine Audiodatei reist als `multipart`; MCP nimmt JSON | traegt der Vorgang eine DATEI? |
 * | `notifications` `src/Push/PushKeys.php` | **die NABE** (`push.hub_url`) | der Schluessel wird gebraucht, BEVOR eine Anmeldung steht | braucht man den Wert vor der Anmeldung? |
 *
 * Nur die ersten zwei sind die Aussenwelt. Die anderen zwei reden mit der
 * Mitte und bleiben trotzdem draussen — begruendete Ausnahmen von Regel 2,
 * keine Reste.
 *
 * Gemessen und festgehalten am 12.11.2026, Aufgabe #9882. Ein Auftrag fuehrte
 * alle vier als „Aussenwelt"; bei zwei war das falsch. Der Fehler dahinter ist
 * uebertragbar und lohnt die Zeile: **aus der FORM (ein direkter Aufruf) wurde
 * auf den GEGENSTAND (ein Fremddienst) geschlossen.**
 */
class IcsFeed
{
    /**
     * Wie weit zurueck und wie weit voraus Termine uebernommen werden.
     *
     * Ein Outlook-Feed liefert gern Jahre. Was weiter weg ist als zwei Jahre,
     * interessiert keinen Kalender, und was mehr als ein Jahr her ist, auch
     * nicht — beides bezahlt man sonst mit Zeilen.
     */
    private const JAHRE_ZURUECK = 1;

    private const JAHRE_VORAUS = 2;

    /**
     * Den Feed holen und auswerten.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException wenn die Adresse nicht antwortet oder der
     *                          Auswerter fehlt — NICHT, wenn der Feed leer ist.
     */
    public function holen(string $adresse): array
    {
        $this->auswerterPruefen();

        $antwort = Http::withHeaders([
            // Manche Anbieter liefern ohne erkennbaren Client gar nichts.
            'User-Agent' => 'Mozilla/5.0 (compatible; PeppermintCalendar/1.0)',
            'Accept' => 'text/calendar, */*',
        ])
            ->connectTimeout(30)
            ->timeout(90)
            ->retry(2, 5000)
            ->get($adresse);

        if (! $antwort->successful()) {
            // Eine Ausnahme und keine leere Liste: Der Abgleich raeumt weg, was
            // der Feed nicht mehr nennt — eine leere Antwort wegen eines
            // HTTP-Fehlers wuerde den ganzen Kalender leeren.
            throw new RuntimeException('HTTP-Fehler: '.$antwort->status());
        }

        return $this->auswerten($antwort->body());
    }

    /**
     * ICS-Text in Termine zerlegen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function auswerten(string $ics): array
    {
        $this->auswerterPruefen();

        $kalender = \Sabre\VObject\Reader::read($ics);

        if (! $kalender || ! isset($kalender->VEVENT)) {
            return [];
        }

        $von = CarbonImmutable::now()->subYears(self::JAHRE_ZURUECK);
        $bis = CarbonImmutable::now()->addYears(self::JAHRE_VORAUS);

        $termine = [];

        foreach ($this->vorkommen($kalender, $von, $bis, $this->zone()) as $vevent) {
            $termin = $this->einen($vevent, $von, $bis);

            if ($termin !== null) {
                $termine[] = $termin;
            }
        }

        return $termine;
    }

    /**
     * Die Vorkommen im Fenster — Serien aufgeloest, wenn es geht.
     *
     * `expand()` scheitert an manchen Feeds (fehlerhafte RRULE, unbekannte
     * Zeitzone). Dann lieber die unaufgeloesten Termine als keine: Ein
     * Einzeltermin ist auch ohne Aufloesung richtig, und eine Serie erscheint
     * wenigstens mit ihrem ersten Vorkommen.
     *
     * ## Die Zone MUSS mit
     *
     * `expand()` rechnet schwebende Zeiten (`DTSTART` ohne `Z` und ohne
     * `TZID`) in UTC um. Ohne Zonenangabe nimmt es dafuer UTC an — aus 14 Uhr
     * schwebend wird 14 Uhr UTC, also 15 Uhr Ortszeit. Mit der Zone bleibt die
     * WANDZEIT stehen, und eine Zeit mit `Z` wird weiter korrekt umgerechnet.
     * Beide Faelle stehen in der Pruefreihe; ohne den zweiten waere die
     * Korrektur nur eine Verschiebung des Fehlers.
     *
     * @return iterable<object>
     */
    private function vorkommen(object $kalender, CarbonImmutable $von, CarbonImmutable $bis, string $zone): iterable
    {
        try {
            $aufgeloest = $kalender->expand(
                new \DateTimeImmutable($von->toDateTimeString()),
                new \DateTimeImmutable($bis->toDateTimeString()),
                new \DateTimeZone($zone),
            );

            return $aufgeloest->VEVENT;
        } catch (Throwable $fehler) {
            Log::warning('Serienaufloesung eines Abo-Feeds gescheitert — unaufgeloest uebernommen.', [
                'fehler' => $fehler->getMessage(),
            ]);

            return $kalender->VEVENT;
        }
    }

    /**
     * Ein VEVENT.
     *
     * @return array<string, mixed>|null  null heisst „gehoert nicht hierher"
     *                                    (kein Beginn, oder ausserhalb des
     *                                    Fensters) und ist kein Fehler.
     */
    private function einen(object $vevent, CarbonImmutable $von, CarbonImmutable $bis): ?array
    {
        $beginn = $vevent->DTSTART ?? null;

        if ($beginn === null) {
            return null;
        }

        try {
            $ganztags = $this->ganztags($beginn);
            $start = $this->zeitpunkt($beginn);

            if ($start->lt($von) || $start->gt($bis)) {
                return null;
            }

            $ende = isset($vevent->DTEND)
                ? $this->zeitpunkt($vevent->DTEND)
                : null;

            if ($ende === null) {
                $ende = $ganztags ? $start : $start->addHour();
            } elseif ($ganztags) {
                // Bei einem Ganztagstermin ist DTEND AUSSCHLIESSEND — der
                // 5. bis 6. meint den 5. Ohne diese Zeile ist jeder
                // Ganztagstermin einen Tag zu lang.
                $ende = $ende->subDay();
            }

            $quellKennung = trim((string) ($vevent->UID ?? ''));

            return [
                // Der Schluessel dieser Zeile: Quell-Kennung plus Tag. Ohne
                // den Tag ueberschreiben sich die Vorkommen einer Serie
                // gegenseitig — sie tragen alle dieselbe UID.
                'uid' => Str::limit(
                    ($quellKennung !== '' ? Str::limit($quellKennung, 200, '') : 'ohne-uid-'.$start->format('YmdHis'))
                    .'_'.$start->format('Ymd'),
                    255,
                    ''
                ),
                'source_uid' => $quellKennung === '' ? null : Str::limit($quellKennung, 255, ''),
                'title' => Str::limit(trim((string) ($vevent->SUMMARY ?? '')) ?: 'Ohne Titel', 255, ''),
                'description' => isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
                'location' => isset($vevent->LOCATION) ? Str::limit((string) $vevent->LOCATION, 255, '') : null,
                'start_datetime' => $start,
                'end_datetime' => $ende,
                'all_day' => $ganztags,
            ];
        } catch (Throwable $fehler) {
            // EIN unlesbarer Termin darf den Feed nicht kippen. Gezaehlt wird
            // er trotzdem — ein stilles Ueberspringen waere die Variante, bei
            // der man Monate spaeter fragt, warum ein Termin nie ankam.
            Log::warning('Ein Termin eines Abo-Feeds war nicht lesbar — uebersprungen.', [
                'fehler' => $fehler->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Ist das ein Ganztagstermin?
     *
     * Am Parameter `VALUE=DATE` und nicht an der Uhrzeit: Ein Termin um
     * Mitternacht ist kein Ganztagstermin, und ein Ganztagstermin hat gar keine
     * Uhrzeit.
     */
    private function ganztags(object $beginn): bool
    {
        $wert = $beginn->parameters['VALUE'] ?? null;

        return $wert !== null && Str::upper((string) $wert) === 'DATE';
    }

    /**
     * Ein Zeitpunkt aus dem Feed, in der Zeitzone DIESER Anwendung.
     *
     * Nicht fest `Europe/Berlin`, wie es die Manager-Fassung hatte: Die Spalte
     * wird in `app.timezone` zurueckgelesen, und genau diese Annahme hat bei
     * den Ganztags-Erinnerungen schon eine Stunde gekostet
     * ({@see \Peppermint\Calendar\Reminders\LeadMoment}).
     *
     * ## Die SCHWEBENDE Zeit — und warum sie eine Stunde gekostet hat
     *
     * `DTSTART:20261030T140000` traegt WEDER ein `Z` noch ein `TZID`. Das ist
     * im iCalendar-Standard eine „floating time": 14 Uhr dort, wo der Leser
     * sitzt. Die Manager-Fassung rief `getDateTime()` ohne Vorgabezone auf —
     * sabre nimmt dann UTC an — und rechnete das Ergebnis nach Berlin. Aus
     * 14:00 wurden 15:00, im Winter; im Sommer zwei Stunden.
     *
     * Gefunden am 29.10.2026 beim Schreiben der Pruefreihe, nicht im Betrieb:
     * Outlook schickt `TZID`, und mit `TZID` ist die Rechnung richtig. Ein
     * Feed ohne Zone — und davon gibt es viele — haette still verschobene
     * Termine geliefert.
     *
     * Deshalb kommt die Vorgabezone MIT. Fuer eine schwebende Zeit bleibt die
     * Wandzeit stehen; fuer eine mit `Z` oder `TZID` rechnet sabre richtig um,
     * und das anschliessende `setTimezone` ist dann nur die Normalisierung auf
     * die Zone, in der die Spalte gelesen wird.
     */
    private function zeitpunkt(object $eigenschaft): CarbonImmutable
    {
        $zone = $this->zone();

        return CarbonImmutable::instance($eigenschaft->getDateTime(new \DateTimeZone($zone)))
            ->setTimezone($zone);
    }

    /** Die Zeitzone, in der die Spalte gelesen wird. */
    private function zone(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    private function auswerterPruefen(): void
    {
        if (! class_exists(\Sabre\VObject\Reader::class)) {
            throw new RuntimeException(
                'Abonnierte Kalender brauchen sabre/vobject. '
                .'composer require sabre/vobject — oder die Abos nicht benutzen.'
            );
        }
    }
}
