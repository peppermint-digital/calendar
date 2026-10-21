<?php

use Peppermint\Calendar\Categories\BrainCategorySource;
use Peppermint\Calendar\Exceptions\BrainBridgeMissing;
use Peppermint\Calendar\Sources\BrainCalendarSources;

/**
 * Die beiden Brain-Quellen, die bis zum 20.10.2026 in jedem Produkt lagen.
 *
 * ## Was hier geprüft wird, und was nicht
 *
 * Nicht der Komm-Layer — der gehört der Brücke, und sie ist hier nicht
 * installiert. Geprüft wird genau das, was das Paket selbst verantwortet:
 *
 *   - Fehlt die Brücke, kommt eine Meldung, die sagt WAS fehlt, statt eines
 *     `Class not found` aus der Tiefe einer Quelle.
 *   - Die Wache ist ein Parameter, keine dritte Fassung der Klasse.
 *
 * Der zweite Punkt ist der Grund, warum die Klasse überhaupt ins Paket konnte:
 * Gemessen am Code ohne Kommentare unterschieden sich die drei Produktfassungen
 * in **einer** Zeile — `Auth::guest()` gegen `Auth::guard('web')->guest()`.
 */
it('nennt die fehlende Bruecke beim Namen, statt an einer Klasse zu scheitern', function () {
    // Gegenprobe zur Probe: Die Bruecke ist in dieser Suite wirklich nicht da.
    // Ohne diese Zeile prueft der Test moeglicherweise nichts.
    expect(class_exists('\\Peppermint\\AiBrainBridge\\Facades\\AiBrain'))->toBeFalse();

    expect(fn () => (new BrainCategorySource)())
        ->toThrow(BrainBridgeMissing::class);

    expect(fn () => (new BrainCalendarSources)->build())
        ->toThrow(BrainBridgeMissing::class);
});

/**
 * Die Meldung ist für einen Menschen geschrieben, nicht für ein Protokoll:
 * Sie nennt das fehlende Paket und den Weg daran vorbei.
 */
it('sagt in der Meldung, welches Paket fehlt und was die Alternative ist', function () {
    try {
        (new BrainCategorySource)();
        $this->fail('Es haette werfen muessen.');
    } catch (BrainBridgeMissing $fehler) {
        expect($fehler->getMessage())
            ->toContain('peppermint/ai-brain-bridge')
            ->toContain('config/calendar.php');
    }

    try {
        (new BrainCalendarSources)->build();
        $this->fail('Es haette werfen muessen.');
    } catch (BrainBridgeMissing $fehler) {
        expect($fehler->getMessage())
            ->toContain('peppermint/ai-brain-bridge')
            ->toContain('calendar.sources');
    }
});

/**
 * Die Wache ist der einzige Unterschied, den die drei Produktfassungen hatten.
 * Dass sie als Parameter durchgeht, ist deshalb die Bedingung dafür, dass es
 * EINE Klasse bleiben darf.
 */
it('nimmt die Wache als Parameter', function () {
    $ohne = new BrainCalendarSources;
    $mit = new BrainCalendarSources('web');

    // Beide scheitern hier an der fehlenden Bruecke — der Punkt ist, dass der
    // Parameter ueberhaupt angenommen wird und nicht eine zweite Klasse
    // braucht.
    expect(fn () => $ohne->build())->toThrow(BrainBridgeMissing::class);
    expect(fn () => $mit->build())->toThrow(BrainBridgeMissing::class);
});

/**
 * Der Name des Werkzeugs — der Fehler, den diese Suite strukturell nicht sehen
 * konnte.
 *
 * Die Brücke ist hier nicht installiert, der Aufruf lässt sich also nicht
 * nachstellen. Genau darin lag die Lücke: Der Name stand als Zeichenkette im
 * Aufruf, war falsch (`list-calendar-categories` statt
 * `list-calendar-categories-tool`), und jeder nächtliche Lauf in den Produkten
 * scheiterte daran — monatelang unbemerkt, weil ein zweiter Fehler dieselbe
 * Meldung erzeugte (Verwaltung Bug #1065).
 *
 * Die Nabe benennt JEDES ihrer Werkzeuge mit `-tool` am Ende. Das ist die
 * Zusage, die hier festgehalten wird.
 */
it('ruft das Werkzeug unter dem Namen, den die Nabe vergibt', function () {
    expect(BrainCategorySource::WERKZEUG)
        ->toBe('list-calendar-categories-tool')
        ->toEndWith('-tool');
});
