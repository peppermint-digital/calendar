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
