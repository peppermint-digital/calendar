<?php

namespace Peppermint\Calendar\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Peppermint\Calendar\Ics\IcsExporter;
use Peppermint\Calendar\Models\CalendarEvent;
use Peppermint\Calendar\Support\FeedToken;

/**
 * Der ICS-Feed: einen Kalender abonnieren, statt ihn abzutippen.
 *
 * ## Warum das im Paket steht
 *
 * Am 16.10.2026 hatten zwei von vier Systemen einen Feed (Manager, Brain), CRM
 * und Verwaltung keinen. Der Exporter lag schon hier — es fehlte nur der Weg
 * dorthin, und genau den hat jedes System einzeln gebaut oder eben nicht.
 *
 * ## Warum das mit Sorgfalt gebaut ist
 *
 * Dies ist die **einzige nicht angemeldete Adresse** des Kalenders. Wer den
 * Token hat, liest den Kalender — ohne Konto, ohne Sitzung, aus jedem
 * Kalenderprogramm der Welt. Das ist der Sinn der Sache (Volker abonniert
 * seinen Kalender in seinem Programm), und es ist zugleich die Stelle, an der
 * ein Fehler nicht auffällt: Ein Feed, der zu viel ausliefert, sieht genauso
 * aus wie einer, der richtig arbeitet.
 *
 * Deshalb sind drei Dinge hier festgelegt und nicht dem Produkt überlassen:
 *
 * **Der Token muss lang sein.** Ein kurzer oder leerer Token wird abgewiesen,
 * bevor die Datenbank überhaupt gefragt wird. Der naheliegende Angriff
 * `/calendar/feed/.ics` fängt schon das Routing ab — ein Platzhalter darf nicht
 * leer sein. Durchkommen würde WEISSRAUM: lang genug für jede Längenprüfung
 * und nach `trim()` leer. Ohne diese Grenze wäre das eine Abfrage mit leerem
 * Wert, und ein Produkt, das in der Spalte versehentlich einen leeren String
 * stehen lässt, liefert dann dessen Kalender an jeden aus.
 *
 * **Nur eigene und besuchte Termine.** `visibleTo` heisst Eigentum ODER
 * Teilnahme — dasselbe, was diese Person in ihrem Kalender sieht. Nicht mehr:
 * Ein Feed ist keine Gelegenheit, Rechte neu zu erfinden.
 *
 * **Keine fremden Kalender.** Termine aus anderen Systemen bleiben draussen.
 * Sie liegen dort, sind dort abonnierbar, und ein Feed, der bei jedem Abruf
 * drei Fremdsysteme befragt, ist langsam und fällt mit ihnen aus.
 */
class IcsFeedController
{
    public function __invoke(string $token, IcsExporter $exporter): Response
    {
        $person = $this->personZu($token);

        // 404 und nicht 403: Ein 403 bestätigt, dass es den Token gibt.
        abort_if($person === null, 404);

        [$von, $bis] = $this->fenster();

        $termine = CalendarEvent::query()
            ->visibleTo((int) $person->getKey())
            ->inRange($von, $bis)
            ->orderBy(CalendarEvent::column('starts_at'))
            ->get();

        return response(
            $exporter->calendar($termine, (string) config('app.name')),
            200,
            [
                'Content-Type' => 'text/calendar; charset=utf-8',
                // `inline`, NICHT `attachment`: Ein Abonnement wird abgerufen,
                // nicht heruntergeladen. Mit `attachment` legen manche
                // Programme bei jeder Aktualisierung eine Datei im
                // Download-Ordner ab.
                'Content-Disposition' => 'inline; filename="calendar.ics"',
                // Kein Zwischenspeicher: Ein Kalenderprogramm fragt alle paar
                // Minuten, und ein zwischengespeicherter Feed zeigt Termine,
                // die es nicht mehr gibt.
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
            ],
        );
    }

    /**
     * Wer zu diesem Token gehört — oder null.
     *
     * Die Spalte nennt das Produkt (`calendar.feed.token_column`), weil sie in
     * den vier Systemen verschieden heisst. Das Modell ebenso.
     */
    private function personZu(string $token): ?object
    {
        $token = trim($token);

        // Vor der Datenbank, nicht danach: Ein leerer oder kurzer Token darf
        // gar keine Abfrage auslösen, sonst findet er eine Zeile, in der
        // versehentlich ein leerer String steht.
        //
        // Dieselbe Grenze wie in `FeedToken::acceptable()` — dort, damit ein
        // Produkt sie beim ANLEGEN prüfen kann, statt zu merken, dass der Feed
        // 404 gibt.
        if (! FeedToken::acceptable($token)) {
            return null;
        }

        $modell = config('calendar.user_model');
        $spalte = config('calendar.feed.token_column', 'calendar_token');

        return $modell::query()->where($spalte, $token)->first();
    }

    /**
     * Welcher Zeitraum im Feed steht.
     *
     * Rückwärts begrenzt, weil ein Abonnement kein Archiv ist: Jahre alte
     * Termine machen die Datei gross und den ersten Abruf langsam. Vorwärts
     * weiter, weil man nach vorne plant.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function fenster(): array
    {
        $jetzt = CarbonImmutable::now();

        return [
            $jetzt->subDays((int) config('calendar.feed.past_days', 90))->startOfDay(),
            $jetzt->addDays((int) config('calendar.feed.future_days', 365))->endOfDay(),
        ];
    }
}
