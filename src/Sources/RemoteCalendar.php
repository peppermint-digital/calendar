<?php

namespace Peppermint\Calendar\Sources;

/**
 * Ein fremdes System, das einen Kalender anbietet — soweit dieses Paket es
 * kennen muss.
 *
 * ## Warum ein Vertrag und keine abstrakte Quelle
 *
 * Die Mechanik, aus einem fremden Kalender Termine zu machen, ist in jeder
 * Anwendung dieselbe: fragen, abbilden, Fehlschlaege unterscheiden, die Person
 * aufloesen. Der WEG dorthin ist es nicht — AI Brain ruft seine angebundenen
 * Produkte ueber den eigenen Gateway, ein Produkt ruft ueber die Bridge
 * (`AiBrain::gateway(...)`), und wer beides nicht hat, ruft irgendetwas
 * Drittes.
 *
 * Steckte der Weg in der Quelle, gaebe es die Quelle zweimal. Steckten die
 * Typen des Brains im Paket (`ConnectedProduct`, `ProductGateway`), traege
 * jedes andere Produkt Brain-Felder mit — und genau das ist die schlimmere
 * Doppelpflege. Also: die Mechanik hier, der Weg beim Gastgeber.
 *
 * ## Die vier Fragen, die nur der Gastgeber beantworten kann
 *
 * 1. **Wie heisst das System** (`key`, `label`) — der Schluessel steckt in
 *    jeder Termin-Kennung („manager:7") und in der gespeicherten Filterauswahl
 *    der Nutzer. Er ist damit gespeicherte Form und darf sich nicht aendern.
 * 2. **Was bietet es an** (`offers`) — gemessen an einem Faehigkeitsregister,
 *    nicht geraten. Verliert das Produkt ein Werkzeug, verschwindet die Quelle
 *    von selbst, statt in eine Fehlermeldung zu laufen.
 * 3. **Darf diese Person dort etwas** (`mayWrite`) — eine Rechtefrage, und
 *    Rechte liegen nicht im Paket. Getrennt von `offers()`, weil ein System
 *    seinen Kalender zeigen kann, ohne dass jemand darin schreiben darf.
 * 4. **Wer ist diese Person drueben** (`identify`) — zwei Systeme, zwei
 *    Benutzertabellen. Ohne Aufloesung findet der Vorgang NICHT statt.
 */
interface RemoteCalendar
{
    /**
     * Stabiler Schluessel, klein geschrieben, ohne Herstellerpraefix.
     *
     * Er steckt in den Termin-Kennungen und in gespeicherten Filtern — eine
     * Umbenennung macht die Filter der Nutzer stumm falsch, ohne dass etwas
     * bricht. Also: einmal waehlen, dann lassen.
     */
    public function key(): string;

    /** Was ein Mensch im Filter und am Termin liest. */
    public function label(): string;

    /**
     * Bietet das System diesen Vorgang an?
     *
     * Die Namen sind die des Pakets: `calendar.events.list`,
     * `calendar.events.show`, `calendar.events.create`,
     * `calendar.events.update`, `calendar.events.delete`,
     * `calendar.kinds.list`.
     */
    public function offers(string $capability): bool;

    /**
     * Darf diese Person im fremden System anlegen, verschieben und loeschen?
     *
     * NUR die Rechtefrage — ob das System die Vorgaenge ueberhaupt anbietet,
     * fragt `offers()`. Beides zu vermischen hiesse, dass eine fehlende
     * Freischaltung aussieht wie ein Produkt ohne Kalender.
     *
     * Fail-closed: Ohne angemeldete Person `false`. Ein Hintergrundlauf soll
     * nicht im Namen irgendwessen in fremde Systeme schreiben.
     */
    public function mayWrite(int $userId): bool;

    /**
     * Die Kennung dieser Person im fremden System — oder `null`.
     *
     * `null` heisst: Der Vorgang findet nicht statt. Ein Termin, der drueben
     * unter einer geratenen Identitaet entsteht, ist schlimmer als einer, der
     * nicht entsteht; und ein Kalender, der bei unbekannter Identitaet den
     * ganzen Team-Kalender zeigt, war ein echter Fehler (Bug #657).
     */
    public function identify(int $userId): ?string;

    /**
     * Wurzeladresse des Systems, um ein Sprungziel zu bauen — oder `null`.
     *
     * Nur Rueckfall. Liefert ein Werkzeug die Adresse seines Termins selbst
     * mit, gilt die: Das Produkt kennt seine eigenen Wege. Ein geratener Pfad
     * zeigte beim Manager auf `/tasks/{id}` und waere fuer die Verwaltung
     * falsch gewesen (`/tickets/{id}`) — aufgefallen ist das nur zufaellig.
     */
    public function baseUrl(): ?string;

    /**
     * Einen Vorgang drueben ausloesen.
     *
     * Wirft nicht. Ein nicht erreichbares System ist der Normalfall und kein
     * Ausnahmezustand — wer hier wirft, zwingt jeden Aufrufer zu einem
     * `try`, und einer vergisst es.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $capability, array $arguments = []): RemoteResult;
}
