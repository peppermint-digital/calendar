<?php

namespace Peppermint\Calendar\Sources;

use Closure;

/**
 * Ein fremder Kalender, beschrieben durch seine Angaben und einen Aufrufweg.
 *
 * ## Warum es diese Klasse gibt
 *
 * {@see RemoteCalendar} ist ein Vertrag, und ein Vertrag will erfuellt werden.
 * Beim ERSTEN Abnehmer sah die Erfuellung produktspezifisch aus: eine Klasse,
 * die Slug, Name, Adresse und Faehigkeiten haelt und Aufrufe ueber den
 * Kommunikationslayer der Anwendung schickt.
 *
 * Beim ZWEITEN war sie zeichengleich — bis auf das Benutzermodell und den
 * Anmelde-Guard. Genau an dieser Stelle verlangt der Paketvertrag die Frage
 * „schreibt jedes Produkt denselben Verdrahtungscode?", und die Antwort war
 * ja. Also wandert sie hierher, bevor die zweite Kopie entsteht und ab dem
 * ersten Fix auseinanderlaeuft.
 *
 * ## Warum ein Closure und keine Abhaengigkeit
 *
 * Der Aufrufweg unterscheidet die Anwendungen wirklich: Ein Hub ruft seine
 * angebundenen Produkte ueber den eigenen Gateway, ein Produkt ueber sein
 * SDK, ein Dritter ueber irgendetwas anderes. Wuerde dieses Paket eines davon
 * voraussetzen, zwaenge es jedem Abnehmer eine Abhaengigkeit auf, die zwei von
 * drei nicht brauchen.
 *
 * Ein Closure ist dafuer kein Trick, sondern die genaue Beschreibung: „gib mir
 * eine Antwort auf einen Vorgang". Die Anwendung schreibt dafuer eine Zeile,
 * statt sechzig.
 *
 * ```php
 * new CallableRemoteCalendar(
 *     key: 'verwaltung',
 *     label: 'Verwaltung',
 *     capabilities: ['calendar.events.list', 'calendar.kinds.list'],
 *     transport: fn (string $vorgang, array $daten) => $client->call($vorgang, $daten, 'peppermint-verwaltung'),
 *     identify: fn (int $userId) => User::find($userId)?->email,
 *     baseUrl: 'https://vw.example.com',
 * );
 * ```
 *
 * Wer mehr braucht — eigene Rechteabfrage, ein anderes `label()` — erfuellt
 * den Vertrag weiterhin selbst. Diese Klasse ist die Abkuerzung fuer den
 * Regelfall, nicht die einzige Tuer.
 */
class CallableRemoteCalendar implements RemoteCalendar
{
    /**
     * @param  string  $key  Stabil, klein, ohne Herstellerpraefix — er steckt in den Termin-Kennungen
     *                       („manager:7") und in den gespeicherten Filtern der Nutzer.
     * @param  array<int, string>  $capabilities  Was das System kann UND hier ausgeloest werden darf.
     * @param  Closure(string, array<string, mixed>): RemoteResult  $transport
     * @param  Closure(int): ?string  $identify  Die Kennung der Person im fremden System. `null` heisst:
     *                                           Der Vorgang findet nicht statt.
     * @param  Closure(int): bool|null  $mayWrite  Eigene Rechteabfrage. Ohne sie gilt: Wer benennbar ist,
     *                                             darf es versuchen — die Absage kommt dann vom Zielsystem,
     *                                             laut und mit Grund. Eine zweite Rechteliste hier waere
     *                                             die schlechtere Wahl, weil sie altert, ohne aufzufallen.
     */
    public function __construct(
        protected readonly string $key,
        protected readonly string $label,
        protected readonly array $capabilities,
        protected readonly Closure $transport,
        protected readonly Closure $identify,
        protected readonly ?string $baseUrl = null,
        protected readonly ?Closure $mayWrite = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function offers(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function mayWrite(int $userId): bool
    {
        if ($this->mayWrite !== null) {
            return (bool) ($this->mayWrite)($userId);
        }

        // Ohne eigene Abfrage: Wer benennbar ist, darf es versuchen. Das ist
        // keine Nachlaessigkeit — die Rechte liegen im Zielsystem, und sie hier
        // zu verdoppeln hiesse, eine zweite Liste zu fuehren, die altert.
        return $this->identify($userId) !== null;
    }

    public function identify(int $userId): ?string
    {
        $kennung = ($this->identify)($userId);

        return is_string($kennung) && $kennung !== '' ? $kennung : null;
    }

    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function call(string $capability, array $arguments = []): RemoteResult
    {
        $antwort = ($this->transport)($capability, $arguments);

        // Ein Transport, der etwas anderes liefert, ist ein Fehler im
        // Aufrufer — aber er darf den Kalender nicht mitnehmen. Also derselbe
        // Weg wie bei einem nicht erreichbaren System: ein Fehlschlag mit
        // Grund, kein Absturz mitten im Zeichnen.
        return $antwort instanceof RemoteResult
            ? $antwort
            : RemoteResult::failed(
                'Der Aufrufweg zu '.$this->label.' hat kein '.RemoteResult::class.' geliefert.',
                'tool_failed',
            );
    }
}
