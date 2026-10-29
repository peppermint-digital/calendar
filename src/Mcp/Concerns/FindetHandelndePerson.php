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
        if ($this->brueckeEingerichtet()) {
            $adresse = ('\\Peppermint\\AiBrainBridge\\Facades\\AiBrain')::actingUserEmail();

            if (is_string($adresse) && trim($adresse) !== '') {
                $modell = (string) config('calendar.user_model');

                return class_exists($modell)
                    ? $modell::query()->where('email', trim($adresse))->first()
                    : null;
            }
        }

        return auth()->user();
    }

    /**
     * Ist die Bruecke EINGERICHTET — nicht bloss installiert?
     *
     * Der Unterschied hat am 18.10.2026 einen halben Tag gekostet, und ich bin
     * am 29.10.2026 noch einmal hineingelaufen: `class_exists` sagt in AI Brain
     * „ja", weil der Hub das Paket fuer die Umschaltleiste braucht — ohne es
     * einzurichten. Der erste Aufruf wirft dann
     * `BindingResolutionException: Unresolvable dependency … array $config`,
     * und bei den fragenden Produkten kam das als „Something went wrong" an.
     *
     * Die Frage beantwortet deshalb das Bruecken-Paket selbst
     * ({@see \Peppermint\AiBrainBridge\Verbund\Bruecke::eingerichtet()}) —
     * es versucht, den Manager aufzuloesen, statt nach der Klasse zu fragen.
     *
     * `class_exists` bleibt davor stehen, aber auf der RICHTIGEN Klasse: Fuer
     * dieses Paket ist `peppermint/ai-brain-bridge` ein `suggest` (Regel 7),
     * es kann also ganz fehlen.
     */
    private function brueckeEingerichtet(): bool
    {
        $pruefung = '\\Peppermint\\AiBrainBridge\\Verbund\\Bruecke';

        return class_exists($pruefung) && $pruefung::eingerichtet();
    }
}
