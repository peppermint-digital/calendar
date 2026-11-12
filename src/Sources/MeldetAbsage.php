<?php

namespace Peppermint\Calendar\Sources;

/**
 * Eine Terminquelle, die „ich wurde abgelehnt" von „ich konnte nicht
 * nachsehen" unterscheiden kann.
 *
 * ## Warum ein ZWEITER Vertrag neben {@see MeldetAusfall}
 *
 * {@see MeldetAusfall::istAusgefallen()} heisst „das andere System hat nicht
 * geantwortet". Diese Bedeutung bleibt, und das ist der ganze Grund fuer einen
 * eigenen Vertrag: Haette man sie auf „hat nicht brauchbar geantwortet"
 * geweitet, traege ein vorhandener Anzeiger ploetzlich zwei Lagen — und ein
 * Anzeiger mit zwei Bedeutungen ist schlimmer als zwei Anzeiger. Dasselbe
 * Argument, mit dem {@see EventSourceRegistry::ziele()} ein ausgefallenes Ziel
 * nicht als `writable: true` hereingibt.
 *
 * ## Was dieser Vertrag meldet
 *
 * Die Gegenstelle WAR erreichbar und hat den Zugang abgelehnt — am Komm-Layer
 * `not_allowed`, auf dem Naben-Zweig HTTP 401 oder 403. Das ist eine dritte
 * Lage neben den beiden aus Wiki #595:
 *
 *   - **„Niemand ist zustaendig"** — soll verschwinden.
 *   - **„Ich konnte nicht nachsehen"** — soll sich melden ({@see MeldetAusfall}).
 *   - **„Ich durfte nicht nachsehen"** — soll sich melden, aber mit einem
 *     ANDEREN Satz. Hier.
 *
 * Der Unterschied ist nicht kosmetisch, sondern der naechste Schritt: Ein
 * Ausfall geht vorueber, eine Absage behebt ein Mensch. Wer beides gleich
 * benennt, schickt die Person entweder vergeblich warten oder vergeblich zur
 * Administration.
 *
 * Genau das war Bastians Befund am 12.11.2026 (Aufgabe #9882): In der
 * Anlege-Maske des Managers stand „Nicht erreichbar: AI Brain (privat)" —
 * ueber ein System, das geantwortet und mit HTTP 401 abgelehnt hatte.
 *
 * ## Freiwillig, und deshalb mit einem Waechter
 *
 * Wie {@see MeldetAusfall} und {@see ErklaertAbwesenheit} ein optionaler
 * Vertrag, per `instanceof` gefragt: Eine Pflichtmethode an
 * {@see EventSource} zwaenge jede Quelle in vier Produkten dazu, etwas zu
 * beantworten, was die meisten nicht wissen koennen.
 *
 * Der Preis davon ist bekannt und steht in Wiki #595: **Ein optionaler
 * Vertrag bricht nichts, er FEHLT nur** — und niemand merkt es, weil die
 * Maske aufgeht und der Hinweis einfach ausbleibt. Deshalb gehoert zu diesem
 * Vertrag ein Waechtertest, der jede Quelle durchgeht, die den Zustand
 * ueberhaupt kennen KANN, und verlangt, dass sie ihn auch meldet.
 *
 * ## Wer ihn liest
 *
 * NICHT das Produkt. {@see EventSourceRegistry::ziele()} liest ihn und gibt
 * die Markierung fertig heraus, als eigenes Merkmal neben `unreachable`.
 */
interface MeldetAbsage
{
    /**
     * Hat das andere System geantwortet und den Zugang ABGELEHNT?
     *
     * Nur dieser eine Zustand. Insbesondere NICHT:
     *
     *   - eine fehlende Freischaltung — die Gegenstelle hat geantwortet und
     *     das Werkzeug stand nicht in der Liste; das ist eine Auskunft und
     *     soll verschwinden,
     *   - ein Ausfall — der gehoert zu {@see MeldetAusfall},
     *   - eine fehlende Bruecke — kein Netzweg, keine Absage.
     *
     * Wer hier mehr als die Absage mit `true` beantwortet, baut den Fehler
     * wieder ein, den dieser Vertrag behebt — nur in der anderen Richtung.
     */
    public function istAbgelehnt(): bool;
}
