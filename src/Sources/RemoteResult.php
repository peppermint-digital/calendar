<?php

namespace Peppermint\Calendar\Sources;

/**
 * Die Antwort eines fremden Systems auf einen Vorgang.
 *
 * Absichtlich armselig: vier Felder, kein Verhalten. Sie ist die Naht zwischen
 * dem Paket und dem Weg, auf dem die Anwendung mit anderen Systemen spricht —
 * und eine Naht, die etwas kann, wird zur zweiten Stelle, an der Dinge
 * entschieden werden.
 *
 * `ok` ist nicht aus `data` ableitbar. Ein Vorgang kann gelingen und nichts
 * zurueckgeben (Loeschen), und er kann fehlschlagen und trotzdem etwas
 * mitschicken (eine Fehlerbeschreibung). Wer auf `data !== null` prueft, haelt
 * das eine fuer das andere.
 */
class RemoteResult
{
    /**
     * @param  array<mixed>|null  $data  Was das Zielsystem geliefert hat — seine Fassung, nicht die geschickte.
     * @param  string|null  $message  Fuer Menschen lesbar. Steht in der Fehlermeldung, die jemand sieht.
     * @param  string|null  $error  Klasse des Fehlschlags, soweit die Gegenstelle eine nennt:
     *                              `not_offered`, `not_allowed`, `unreachable`, `tool_failed`.
     *                              Dafuer da, dass ein Aufrufer weiss, ob ein zweiter Versuch Sinn hat —
     *                              ohne Zeichenketten zu raten.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?array $data = null,
        public readonly ?string $message = null,
        public readonly ?string $error = null,
    ) {}

    /** @param array<mixed>|null $data */
    public static function ok(?array $data = null): self
    {
        return new self(true, $data);
    }

    public static function failed(string $message, ?string $error = null): self
    {
        return new self(false, null, $message, $error);
    }

    /**
     * Aus der Antwort eines Kommunikationslayers.
     *
     * Die vier Schluessel `ok`, `data`, `message`, `error` sind die Form, in
     * der jeder uns bekannte Weg antwortet. Die Umwandlung hier zu haben statt
     * in jedem Produkt ist kein Luxus: Beim zweiten Abnehmer stand sie
     * zeichengleich da, beim dritten waere sie es wieder — und sie ist genau
     * die Stelle, an der ein `??` falsch gesetzt aus einem Fehlschlag einen
     * stillen Erfolg macht.
     *
     * `message` faellt auf `error` zurueck: Eine Gegenstelle, die nur die
     * Fehlerklasse nennt, soll nicht zu einer Fehlermeldung ohne Text fuehren
     * — „nicht erreichbar: " ist schlimmer als „nicht erreichbar:
     * unreachable".
     *
     * ## `ok` wird STRENG gelesen, und das ist keine Stilfrage
     *
     * Nur ein echtes `true` zaehlt. Bis zum 11.11.2026 stand hier
     * `(bool) ($antwort['ok'] ?? false)`, und der Fall, den diese Lesart
     * falsch beantwortet, ist dieser:
     *
     *     ['ok' => 'false', 'data' => null]   // (bool) 'false' === true
     *
     * Eine so antwortende Gegenstelle gilt damit als ERFOLG. `data` ist dann
     * keine Liste, `rows()` ist leer — und heraus kommt eine leere
     * Terminliste, die sich als vollstaendige Auskunft ausgibt.
     *
     * Beim Anlegen ist es schlimmer: Dort entscheidet dieselbe Zeile, und
     * {@see RemoteEventSource::durchgefallen()} laesst eine ABGELEHNTE Anlage
     * als Erfolg durch. `ExternalSourceFailed` greift nicht mehr — die
     * Vorkehrung gegen „ein Termin, den jemand angelegt zu haben glaubt und
     * den es nirgends gibt" wird von der einen Zeile ausgehebelt, die sie
     * tragen sollte.
     *
     * Dasselbe gilt fuer `1`, `'1'`, `'yes'` und jedes andere Truthy, das kein
     * Bool ist: Alle sind eine KAPUTTE Antwort, und eine kaputte Antwort ist
     * kein Erfolg. Streng gelesen kostet ein sich falsch verhaltendes System
     * eine Fehlermeldung; lose gelesen kostet es eine falsche Auskunft, die
     * niemand sieht.
     *
     * Wer das wieder lockern will, will diese leere Liste — und beim Anlegen
     * diesen Termin, den es nicht gibt.
     *
     * @param  array<string, mixed>  $antwort
     */
    public static function fromArray(array $antwort): self
    {
        return new self(
            ok: ($antwort['ok'] ?? null) === true,
            data: is_array($antwort['data'] ?? null) ? $antwort['data'] : null,
            message: $antwort['message'] ?? $antwort['error'] ?? null,
            error: $antwort['error'] ?? null,
        );
    }

    /**
     * Die Zeilen der Antwort — jede fuer sich, und nur die, die eine sind.
     *
     * Eine Liste, in der eine Zeichenkette zwischen den Zeilen liegt, ist
     * nicht automatisch unbrauchbar: Der Rest bleibt lesbar, und ein fremdes
     * System soll den Kalender nicht dadurch leeren koennen, dass es einmal
     * etwas Unerwartetes mitschickt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return array_values(array_filter((array) ($this->data ?? []), 'is_array'));
    }
}
