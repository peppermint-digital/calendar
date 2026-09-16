<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonImmutable;

/**
 * Eine Quelle, in der man auch anlegen, verschieben und loeschen darf.
 *
 * Bewusst eine eigene Klasse und kein Schalter an `EventSource`. Ein Schalter
 * waere eine Abfrage, die jemand vergisst — und die Folge waere ein Schreibweg
 * in ein System, das nur angezeigt werden sollte. So bleibt eine lesende
 * Quelle lesend, weil sie die Methode gar nicht hat.
 *
 * Der Termin gehoert weiterhin dem anderen System: Was hier zurueckkommt, ist
 * ein `ExternalEvent` — ohne Zeile in dieser Datenbank, wie beim Lesen.
 */
abstract class WritableEventSource extends EventSource
{
    /**
     * Die Terminarten des anderen Systems, damit ein Mensch eine auswaehlen
     * kann statt sie zu raten. Leer heisst: Es nennt keine, und der Aufrufer
     * muss sich auf die Vorgabe des Zielsystems verlassen.
     *
     * @return array<int, ExternalKind>
     */
    abstract public function kinds(): array;

    /**
     * Legt den Termin drueben an und gibt zurueck, was das andere System
     * daraus gemacht hat — nicht, was wir geschickt haben. Die beiden koennen
     * sich unterscheiden: Das Zielsystem rundet Zeiten, ergaenzt Vorgaben oder
     * haengt seine eigene Kennung an, und angezeigt werden muss seine Fassung.
     */
    abstract public function create(int $userId, NewExternalEvent $event): ExternalEvent;

    /**
     * Verschiebt den Termin drueben — neuer Anfang, neues Ende.
     *
     * Der haeufigste Eingriff ueberhaupt und der einzige, der beim Ziehen im
     * Raster entsteht. Deshalb ein eigener Vorgang und kein „aendere alles":
     * Wer eine Stunde nach hinten zieht, will nicht, dass dabei Titel,
     * Teilnehmer und Serie mitgeschickt werden — und schon gar nicht in der
     * Fassung, die diese Anwendung gerade zufaellig im Speicher hat.
     *
     * `$allDay` ist `null`, wenn sich daran nichts aendert. Gesetzt wird es
     * beim Ziehen zwischen Ganztagszeile und Zeitraster — ohne diesen Weg
     * braeuchte die Oberflaeche dafuer einen zweiten, und der kennt die Regeln
     * dann irgendwann nicht mehr.
     *
     * Bei einer Serie entscheidet das Zielsystem, ob ein Vorkommen oder die
     * Regel wandert. Der Vertrag reicht durch, er legt es nicht aus: Was eine
     * Serie bedeutet, weiss nur, wem sie gehoert.
     *
     * @throws \Peppermint\Calendar\Exceptions\ExternalSourceFailed
     */
    abstract public function move(
        int $userId,
        string $eventId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?bool $allDay = null,
    ): ExternalEvent;

    /**
     * Loescht den Termin drueben.
     *
     * Ohne Rueckgabe: Was es nicht mehr gibt, kann nichts zurueckgeben. Ein
     * Fehlschlag wirft — stillschweigend nichts zu tun waere hier am
     * schlimmsten, weil die Oberflaeche die Zeile bereits entfernt hat.
     *
     * @throws \Peppermint\Calendar\Exceptions\ExternalSourceFailed
     */
    abstract public function delete(int $userId, string $eventId): void;

    /**
     * Darf diese Person hier gerade anlegen, aendern und loeschen?
     *
     * EIN Schalter fuer alle drei Vorgaenge, nicht drei. Getrennte Rechte
     * waeren drei Abfragen, und die dritte vergisst jemand — dieselbe
     * Ueberlegung, aus der diese Klasse eine eigene ist und kein Schalter an
     * `EventSource`.
     *
     * Getrennt von `isAvailable()`: Eine Quelle kann erreichbar sein und
     * trotzdem nicht beschreibbar — etwa wenn das Zielsystem den Vorgang zum
     * Anlegen nicht anbietet oder die handelnde Person dort keine Rechte hat.
     * Eine Oberflaeche soll den Knopf dann gar nicht erst zeigen.
     */
    public function isWritable(): bool
    {
        return true;
    }
}
