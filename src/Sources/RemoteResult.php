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
