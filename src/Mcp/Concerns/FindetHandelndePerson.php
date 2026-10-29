<?php

namespace Peppermint\Calendar\Mcp\Concerns;

/**
 * Wer ruft hier eigentlich an?
 *
 * ## Warum das Paket das braucht
 *
 * Ein Vorlauf gehoert immer genau einem Menschen. Gepflegt wird er aber im
 * AI Brain, fuer alle Systeme an einer Stelle — dieselbe Entscheidung wie bei
 * den Benachrichtigungen (#520), bestaetigt am 29.10.2026: „Das ist ja unser
 * Hauptkalender-Paket bzw. Erinnerungspaket und verwaltet ja auch alle
 * notifications."
 *
 * Das Brain ruft ein Produkt dabei als CLIENT auf, und ein Client hat keine
 * Identitaet — der Dienst-Modus ist userlos. „Chris' Vorlauf im Manager
 * setzen" braucht deshalb eine ausdrueckliche Person, und die kommt als
 * E-MAIL-ADRESSE in der Kopfzeile des Aufrufs.
 *
 * Die Adresse ist der richtige Schluessel und nicht die Nummer: IDs sind je
 * Datenbank verschieden, ein Mensch ist in vier Systemen vier Mal angelegt.
 * Dieselbe Entscheidung wie beim Slug einer Kalenderkategorie.
 *
 * ## Warum das hier steht und nicht aus dem Meldungs-Paket kommt
 *
 * `peppermint/notifications` hat denselben Trait. Ihn von dort zu nehmen
 * hiesse, dass der Kalender vom Meldungswesen abhaengt — und das Meldungs-
 * Paket zieht `peppermint/inbox` und `peppermint/ai-brain-bridge` mit. Regel 7
 * des Paketvertrags: Ein Kalender muss in einem Produkt ohne Meldungswesen
 * installierbar bleiben. Also dieselben zwanzig Zeilen, und dafuer keine
 * Kette zwischen zwei Kernpaketen.
 *
 * Ohne Bruecke ist es die angemeldete Person. Beides abzufragen kostet nichts
 * und macht die Werkzeuge auch ohne Hub benutzbar.
 */
trait FindetHandelndePerson
{
    /**
     * Die handelnde Person — oder `null`, wenn es keine gibt.
     *
     * `null` ist ein gueltiges Ergebnis und kein Fehler. Was daraus folgt,
     * entscheidet das aufrufende Werkzeug: beim Lesen eine Antwort ohne
     * Person, beim Schreiben eine Absage. Siehe dort.
     */
    protected function handelnde(): mixed
    {
        $bruecke = '\\Peppermint\\AiBrainBridge\\Facades\\AiBrain';

        if (class_exists($bruecke)) {
            $adresse = $bruecke::actingUserEmail();

            if (is_string($adresse) && trim($adresse) !== '') {
                $modell = (string) config('calendar.user_model');

                return class_exists($modell)
                    ? $modell::query()->where('email', trim($adresse))->first()
                    : null;
            }
        }

        return auth()->user();
    }
}
