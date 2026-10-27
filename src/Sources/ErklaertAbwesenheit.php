<?php

namespace Peppermint\Calendar\Sources;

/**
 * Eine Terminquelle, die sagen kann, WARUM sie gerade nicht angeboten wird.
 *
 * ## Warum es das braucht
 *
 * {@see EventSourceRegistry::available()} filtert mit `isAvailable()`, und
 * `collect()` läuft nur über diese Liste. Eine Quelle, die `false` sagt,
 * verschwindet damit vollständig: kein Abruf, kein Fehler, kein Schalter in
 * der Oberfläche.
 *
 * Das ist richtig — und für eine Bedingung, die an einer PERSON hängt, zu
 * still. Wer seinen Kalender aus der Nabe hier nicht sieht, soll nicht
 * rätseln, ob er leer ist, ob etwas kaputt ist oder ob er ihn nie verbunden
 * hat.
 *
 * Vorher landete die fehlende Freischaltung im Fehlerlog, bei jedem
 * Seitenaufruf. Nachgemessen am 27.10.2026 am Produktionslog des Peppermint
 * Manager:
 *
 *   **73 Vorfälle** — 32 am 26.10., 41 am 27.10.
 *   vier Personen: 34/26/9/4 (userId 5, 16, 10, 9)
 *   Spitze 26 um 12 Uhr
 *   146 Zeilen, weil je Vorfall ZWEI entstehen
 *
 * Die zwei Zeilen sind der Punkt: nicht nur eine `WARNING`, sondern davor eine
 * `ERROR` der Brücke („Tool [list-calendar-bundle-tool] not found").
 *
 * **Kaputt war keiner dieser Vorfälle.** Eine fehlende Freischaltung ist eine
 * Nichtwahl, und die gehört nicht ins Fehlerlog — dort erreicht sie niemanden,
 * den sie betrifft, und verdeckt die echten Fehler. Sie gehört leise in die
 * Oberfläche, für die Person, die sie treffen kann.
 *
 * ## Freiwillig, nicht Pflicht
 *
 * Bewusst ein eigener Vertrag und keine neue Methode an {@see EventSource}:
 * Dieses Paket bedient vier Produkte, und eine Pflichtmethode zwänge jede
 * Quelle überall dazu, etwas zu beantworten, was nur wenige überhaupt
 * beantworten können. Eine Quelle, die diesen Vertrag nicht umsetzt,
 * verschwindet wie bisher stumm.
 *
 * ## Wer ihn liest
 *
 * Das Produkt. Es durchläuft `all()`, nimmt heraus, was in `available()` steht,
 * und fragt den Rest — wer `instanceof ErklaertAbwesenheit` ist und einen
 * Hinweis liefert, kommt in die Oberfläche:
 *
 *     collect(app(EventSourceRegistry::class)->all())
 *         ->reject(fn (EventSource $q) => $q->isAvailable())
 *         ->filter(fn (EventSource $q) => $q instanceof ErklaertAbwesenheit)
 *         ->map(fn (ErklaertAbwesenheit $q) => $q->abwesenheitsHinweis())
 *         ->filter()
 *         ->values();
 */
interface ErklaertAbwesenheit
{
    /**
     * Der Hinweis für die Oberfläche — oder null, wenn es nichts zu sagen gibt.
     *
     * `null` ist der Normalfall für „die Quelle ist abgeschaltet": Darüber muss
     * niemand informiert werden, der sie nicht einschalten kann. Ein Hinweis
     * gehört nur dorthin, wo die Person etwas tun oder jemanden fragen kann.
     *
     * @return array{titel: string, text: string}|null
     */
    public function abwesenheitsHinweis(): ?array;
}
