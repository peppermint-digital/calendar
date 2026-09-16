<?php

namespace Peppermint\Calendar\Sources;

/**
 * Liefert mehrere Quellen, statt selbst eine zu sein.
 *
 * ## Wofuer
 *
 * Eine Quelle je Klasse reicht, solange man beim Programmieren weiss, welche
 * fremden Systeme es gibt. Sobald sie zur Laufzeit dazukommen — angebundene
 * Produkte, die ihre Faehigkeiten selbst melden — braeuchte jedes neue System
 * eine neue Klasse und eine neue Zeile in der Konfiguration. Genau die Zeile
 * vergisst dann jemand, und das neue System erscheint nirgends.
 *
 * Ein Anbieter dreht das um: Er wird einmal eingetragen und entscheidet bei
 * jedem Aufbau der Registry neu, welche Quellen es gerade gibt.
 *
 * ## Warum das faul bleiben muss
 *
 * Ein Anbieter fragt in der Regel eine Datenbank oder ein Register. Die
 * Registry wird erst gebaut, wenn jemand sie anfordert — also nur dort, wo ein
 * Kalender im Spiel ist. Wer hier bei jedem Seitenaufruf eine Abfrage
 * ausloest, bezahlt sie auf jeder Seite, die nie einen Termin zeigt.
 */
interface EventSourceProvider
{
    /** @return array<int, EventSource> */
    public function sources(): array;
}
