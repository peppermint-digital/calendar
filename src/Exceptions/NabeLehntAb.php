<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

/**
 * Die Nabe hat geantwortet — und den Zugang abgelehnt.
 *
 * ## Warum das Paket eine EIGENE Ausnahme dafuer braucht
 *
 * Seit dem 12.11.2026 traegt die Bruecke den HTTP-Status eines gescheiterten
 * MCP-Aufrufs typisiert (`Peppermint\AiBrainBridge\Exceptions\McpAufrufGescheitert`
 * mit `httpStatus` und `istAbsage()`). Dieses Paket darf diese Klasse aber
 * **nicht importieren**: Die Bruecke steht in `suggest`, nicht in `require`
 * (Regel 7 des Paketvertrags — wer keine Nabe hat, soll sie nicht
 * mitinstallieren muessen). Dasselbe gilt fuer
 * `Illuminate\Http\Client\ConnectionException`: `illuminate/http` ist keine
 * Abhaengigkeit dieses Pakets, nachgemessen in der `composer.json`.
 *
 * Ein `use`-Verweis auf eine der beiden waere also eine Klasse, die in einer
 * eigenstaendigen Installation nicht existiert — und `catch` auf einen
 * fehlenden Typ ist kein Fehler, sondern **schlimmer**: Der Zweig wird
 * stillschweigend nie betreten, und nichts wird dabei rot.
 *
 * Deshalb wird die fremde Ausnahme an **einer** Stelle in die Sprache dieses
 * Pakets uebersetzt — in
 * {@see \Peppermint\Calendar\Brain\KalenderFreigabe::werkzeugnamen()}, also
 * genau dort, wo das Paket die Bruecke ohnehin anfasst. Dieselbe Form, in der
 * {@see BrainBridgeMissing} schon heute „die Bruecke fehlt" traegt: Hinter der
 * Uebersetzung kommt nur noch Paket-eigenes Vokabular vor, und die Tests
 * brauchen die Bruecke nicht.
 *
 * ## Was sie NICHT ist
 *
 * Kein Ausfall. Die Nabe war erreichbar und hat geantwortet; abgelehnt wurde
 * der Zugang dieses Produkts oder der handelnden Person. Wer beides
 * zusammenwirft, baut Bug #1417 wieder ein — dort erschien ein HTTP 401 als
 * „Nicht erreichbar: AI Brain (privat)", und das war die falsche Auskunft
 * ueber den Grund.
 *
 * Auch keine fehlende Freischaltung: Die steht in
 * {@see \Peppermint\Calendar\Enums\Freigabezustand::NichtFreigeschaltet} und
 * entsteht aus einer ERFOLGREICHEN Antwort, in deren Werkzeugliste das
 * Werkzeug nur nicht stand.
 */
class NabeLehntAb extends RuntimeException
{
    /**
     * @param  int|null  $status  Der HTTP-Status, den die Nabe geschickt hat —
     *                            401 oder 403. `null` nur, wenn eine
     *                            Gegenstelle eine Absage ohne Status meldet;
     *                            der Zustand ist derselbe, die Meldung aermer.
     */
    public function __construct(public readonly ?int $status = null, string $nachricht = '')
    {
        parent::__construct($nachricht !== ''
            ? $nachricht
            : 'Die Nabe hat den Zugang zum Kalender abgelehnt'.($status !== null ? " (HTTP {$status})" : '').'.');
    }
}
