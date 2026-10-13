<?php

use Peppermint\Calendar\Sources\CallableRemoteCalendar;
use Peppermint\Calendar\Sources\RemoteEventSource;
use Peppermint\Calendar\Sources\RemoteResult;

/**
 * Die Abkuerzung fuer den Regelfall (#7490).
 *
 * Geprueft wird, dass sie den Vertrag wirklich erfuellt — und die zwei
 * Entscheidungen, die sie vorwegnimmt: Wer benennbar ist, darf es versuchen,
 * und ein Transport, der Unsinn liefert, nimmt den Kalender nicht mit.
 */
function kalender(array $ueberschreiben = []): CallableRemoteCalendar
{
    return new CallableRemoteCalendar(
        key: $ueberschreiben['key'] ?? 'verwaltung',
        label: $ueberschreiben['label'] ?? 'Verwaltung',
        capabilities: $ueberschreiben['capabilities'] ?? ['calendar.events.list', 'calendar.kinds.list'],
        transport: $ueberschreiben['transport'] ?? fn (string $v, array $d) => RemoteResult::ok([['vorgang' => $v, 'daten' => $d]]),
        identify: $ueberschreiben['identify'] ?? fn (int $id) => 'person@beispiel.de',
        baseUrl: $ueberschreiben['baseUrl'] ?? 'https://vw.beispiel.de',
        mayWrite: $ueberschreiben['mayWrite'] ?? null,
    );
}

it('reicht Vorgang und Daten unveraendert an den Aufrufweg', function () {
    $ergebnis = kalender()->call('calendar.events.list', ['from' => '2026-10-13']);

    expect($ergebnis->rows()[0])->toBe(['vorgang' => 'calendar.events.list', 'daten' => ['from' => '2026-10-13']]);
});

it('kennt nur die Vorgaenge, die ihm uebergeben wurden', function () {
    // Die Liste ist die Schnittmenge aus „kann das System" und „darf der
    // Aufrufer" — hier wird sie nur weitergegeben, nicht gebildet.
    $k = kalender();

    expect($k->offers('calendar.events.list'))->toBeTrue()
        ->and($k->offers('calendar.events.delete'))->toBeFalse();
});

it('laesst es versuchen, wer benennbar ist', function () {
    // Keine zweite Rechteliste: Die Rechte liegen im Zielsystem, und eine
    // Kopie hier altert, ohne dass es auffaellt. Die Absage kommt dann von
    // drueben — laut und mit Grund.
    expect(kalender()->mayWrite(7))->toBeTrue()
        ->and(kalender(['identify' => fn (int $id) => null])->mayWrite(7))->toBeFalse();
});

it('laesst eine eigene Rechteabfrage vorgehen', function () {
    // Wer mehr weiss — ein Hub mit globaler Rechteverwaltung — soll es sagen
    // duerfen, ohne den ganzen Vertrag selbst zu erfuellen.
    expect(kalender(['mayWrite' => fn (int $id) => false])->mayWrite(7))->toBeFalse();
});

it('haelt eine leere Kennung fuer keine', function () {
    // Eine leere Zeichenkette sieht aus wie eine Antwort und ist keine. Waere
    // sie eine, verglichen der Eigner-Riegel im Paket gegen "" — und liesse
    // jeden Termin ohne Eignerangabe durch.
    expect(kalender(['identify' => fn (int $id) => ''])->identify(7))->toBeNull();
});

it('macht aus einem Unsinn-Transport einen Fehlschlag, keinen Absturz', function () {
    // Ein Fehler im Aufrufer darf den Kalender nicht mitnehmen: derselbe Weg
    // wie bei einem nicht erreichbaren System.
    $ergebnis = kalender(['transport' => fn (string $v, array $d) => ['ok' => true]])->call('calendar.events.list');

    expect($ergebnis->ok)->toBeFalse()
        ->and($ergebnis->error)->toBe('tool_failed')
        ->and($ergebnis->message)->toContain('Verwaltung');
});

it('traegt eine Quelle, die sich wie jede andere verhaelt', function () {
    // Der eigentliche Nachweis: Die Abkuerzung ist keine zweite Art von
    // Quelle, sondern dieselbe — `RemoteEventSource` merkt keinen Unterschied.
    $quelle = new RemoteEventSource(kalender([
        'transport' => fn (string $v, array $d) => RemoteResult::ok([[
            'id' => 7,
            'title' => 'Kundentermin',
            'starts_at' => '2026-10-13T09:00:00+02:00',
            'ends_at' => '2026-10-13T10:00:00+02:00',
        ]]),
    ]));

    $termine = $quelle->events(7, new DateTimeImmutable('2026-10-13'), new DateTimeImmutable('2026-10-14'));

    expect($quelle->key())->toBe('verwaltung')
        ->and($quelle->isAvailable())->toBeTrue()
        ->and($termine[0]->id)->toBe('7')
        ->and($termine[0]->sourceKey)->toBe('verwaltung');
});
