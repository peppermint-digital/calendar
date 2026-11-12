<?php

namespace Peppermint\Calendar\Sources;

/**
 * Eine Terminquelle, die „ich konnte nicht nachsehen" von „ich bin nicht
 * zustaendig" unterscheiden kann.
 *
 * ## Warum es das braucht
 *
 * {@see EventSourceRegistry::writable()} filtert mit `isWritable()`, und eine
 * Quelle, die `false` sagt, verschwindet vollstaendig aus der Anlege-Maske.
 * Fuer „nicht freigeschaltet" ist das richtig. Fuer einen AUSFALL ist es
 * falsch, und das war Bug #1417: Der private Kalender der Nabe verschwand
 * lautlos, wenn die Nabe nicht erreichbar war.
 *
 * Damit fielen zwei Aussagen zusammen, die keine sind (Wiki #595):
 *
 *   - **„Niemand ist zustaendig"** — soll verschwinden.
 *   - **„Ich konnte nicht nachsehen"** — soll sich melden.
 *
 * ## Der Unterschied zu #1416, und warum er hier traegt
 *
 * Fuer {@see RemoteEventSource} war genau dieser Weg NICHT baubar: Dort liest
 * `isWritable()` eine lokale Faehigkeitsliste und ein lokales Recht — kein
 * Aufruf ins Netz. Die Erreichbarkeit ist dort nicht bekannt, also kann sie
 * auch nicht gemeldet werden; ein unerreichbares Ziel kommt dort als
 * erreichbar herein und faellt erst beim Artenabruf auf (v0.98.0, 502).
 *
 * Bei {@see BrainPrivateEventSource} ist sie bekannt und wurde nur
 * weggeworfen. Deshalb ist „markieren statt filtern" HIER die richtige Form
 * und dort nicht.
 *
 * ## Freiwillig, nicht Pflicht
 *
 * Wie {@see ErklaertAbwesenheit} ein eigener Vertrag und keine neue Methode an
 * {@see EventSource}: Dieses Paket bedient vier Produkte, und eine
 * Pflichtmethode zwaenge jede Quelle dazu, etwas zu beantworten, was nur
 * wenige ueberhaupt beantworten koennen. Eine Quelle ohne diesen Vertrag
 * verschwindet wie bisher stumm — das ist kein Mangel, sondern die Aussage
 * „ich weiss es nicht", und die ist bei #1416 die Wahrheit.
 *
 * ## Wer ihn liest
 *
 * NICHT das Produkt. {@see EventSourceRegistry::ziele()} liest ihn und gibt
 * die Markierung fertig heraus. Das ist der Unterschied zu
 * {@see ErklaertAbwesenheit}, dessen Docblock dem Produkt noch ein Rezept zum
 * Selbstbauen mitgibt — und wo dasselbe Rezept dann in drei Produkten stand.
 */
interface MeldetAusfall
{
    /**
     * Hat das andere System auf die Frage nach der Freigabe nicht geantwortet?
     *
     * Nur der Ausfall, nicht jedes `false`: Eine Quelle, die ordentlich
     * „nein" sagt, ist nicht ausgefallen. Wer hier beides mit `true`
     * beantwortet, baut den Fehler wieder ein, den dieser Vertrag behebt.
     */
    public function istAusgefallen(): bool;
}
