<?php

namespace Peppermint\Calendar\Sources;

use Illuminate\Support\Facades\Auth;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;

/**
 * Die Kalender der anderen Systeme — zur Laufzeit, nicht aus der Konfiguration.
 *
 * ## Warum das jetzt im Paket steht
 *
 * Bis zum 20.10.2026 lag diese Klasse **dreimal** in den Produkten
 * (`FremdeKalenderQuellen` in Manager, CRM und Verwaltung). Gemessen am Code
 * ohne Kommentare: 915 / 915 / 929 Zeichen, und die Unterschiede waren genau
 * zwei —
 *
 *   1. der Namensraum (`App\Calendar` gegen `App\Kalender`),
 *   2. `Auth::guest()` gegen `Auth::guard('web')->guest()` in der Verwaltung.
 *
 * Eine Zeile Abweichung auf drei Kopien. Das ist Frage 3 des Paketvertrags,
 * dreimal mit ja beantwortet — und die Abweichung ist jetzt ein Parameter
 * statt einer dritten Fassung.
 *
 * ## Warum zur Laufzeit und nicht in `config('calendar.sources')`
 *
 * Eine Zeile je Fremdsystem in jedem anderen System ist die Zeile, die beim
 * naechsten Mal fehlt — und das neue System erscheint dann nirgends, ohne
 * Fehler. Das Brain sagt stattdessen, wer `calendar.events.list` anbietet und
 * was dort ausgeloest werden darf; faellt eine Freigabe weg, verschwindet die
 * Quelle von selbst. Es gibt keine zweite Pflegestelle, die man nachziehen
 * muesste.
 *
 * ## Faul, und ohne angemeldete Person gar nicht
 *
 * Gefragt wird erst, wenn jemand die Registry anfordert — sonst bezahlte jede
 * Seite der Anwendung den Netzweg, auch jede ohne Termin. Und fremde Termine
 * gehoeren einer Person: Ein Hintergrundlauf hat keine, und das Brain wiese
 * den Vorgang ohnehin ab.
 *
 * ## Woher die Mailadresse kommt
 *
 * Nicht aus einem Produktmodell — das kennt das Paket nicht. Gefragt wird der
 * Benutzer-Anbieter der Wache, also derselbe Weg, auf dem Laravel auch
 * anmeldet. Damit braucht das Produkt hier keine Zeile, und die Adresse ist
 * dieselbe, die das SDK als handelnde Person mitschickt — nur deshalb greift
 * der Eigner-Riegel im Paket.
 */
class BrainCalendarSources
{
    /**
     * @param  string|null  $guard  Die Wache, die „ist jemand angemeldet" beantwortet.
     *                              `null` = die Vorgabe der Anwendung. Die Verwaltung
     *                              nennt ausdruecklich `web`, weil sie mehrere hat.
     */
    public function __construct(private readonly ?string $guard = null) {}

    /**
     * @return array<int, RemoteEventSource>
     */
    public function build(): array
    {
        $bruecke = '\\Peppermint\\AiBrainBridge\\Facades\\AiBrain';

        if (! class_exists($bruecke)) {
            throw BrainBridgeMissing::forSources();
        }

        $wache = Auth::guard($this->guard);

        if ($wache->guest()) {
            return [];
        }

        $quellen = [];

        foreach ($bruecke::providers('calendar.events.list') as $anbieter) {
            $slug = $anbieter['slug'];

            $quellen[] = new RemoteEventSource(new CallableRemoteCalendar(
                // Schluessel und Beschriftung kommen fertig aus dem SDK —
                // abgeleitet an einer Stelle, nicht hier zum dritten Mal.
                //
                // Der Schluessel steckt in den Termin-Kennungen („manager:7")
                // und in den gespeicherten Filtern der Nutzer. Leitete ihn
                // jedes Produkt selbst ab, hiesse dasselbe System in zwei
                // Oberflaechen irgendwann verschieden — und ein gespeicherter
                // Filter waere stumm falsch, ohne dass etwas bricht.
                key: $anbieter['key'],
                label: $anbieter['label'],
                capabilities: $anbieter['capabilities'] ?? [],
                // MIT Slug: Hier ist ausdruecklich EIN System gemeint. Ohne ihn
                // suchte das Brain sich einen Anbieter, und bei zwei Kalendern
                // waere das der falsche.
                transport: fn (string $vorgang, array $daten) => RemoteResult::fromArray(
                    $bruecke::gateway($vorgang, $daten, $slug),
                ),
                identify: fn (int $userId) => $this->emailOf($userId),
                baseUrl: $anbieter['url'] ?? null,
            ));
        }

        return $quellen;
    }

    /**
     * Die Mailadresse einer Person — ueber den Anbieter der Wache.
     *
     * `retrieveById` ist derselbe Weg, auf dem Laravel beim Anmelden die
     * Person holt. Das Paket braucht dafuer kein Produktmodell zu kennen, und
     * es gibt keine zweite Abfrage derselben Menge.
     */
    private function emailOf(int $userId): ?string
    {
        $person = Auth::guard($this->guard)->getProvider()->retrieveById($userId);

        $adresse = $person?->getAttribute('email');

        return is_string($adresse) && $adresse !== '' ? $adresse : null;
    }
}
