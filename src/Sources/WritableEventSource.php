<?php

namespace Peppermint\Calendar\Sources;

/**
 * Eine Quelle, in der man auch anlegen darf.
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
     * Darf diese Person hier gerade anlegen?
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
