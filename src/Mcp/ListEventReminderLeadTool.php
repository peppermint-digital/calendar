<?php

namespace Peppermint\Calendar\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Calendar\Mcp\Concerns\FindetHandelndePerson;
use Peppermint\Calendar\Reminders\ReminderLead;

/**
 * Wie lange vor einem Termin will die handelnde Person hier erinnert werden?
 *
 * ## Warum dieses Werkzeug gebraucht wird
 *
 * Die Pflegemaske steht an EINER Stelle — im AI Brain, neben der
 * Benachrichtigungs-Matrix (#520, fuer den Vorlauf bestaetigt am 29.10.2026).
 * Dafuer muss das Brain drei Dinge erfahren, die es nicht erraten kann:
 *
 * 1. **Macht dieses Produkt ueberhaupt Termin-Erinnerungen?**
 *    `calendar.reminders.enabled` ist in der Vorgabe aus. Eine Maske, die
 *    einen Vorlauf fuer ein System anbietet, das keine Erinnerungen schickt,
 *    verspricht etwas, das nicht passiert.
 * 2. **Welche Stufen bietet es an?** Die stehen in
 *    `calendar.reminders.lead_choices` und sind je Produkt verschieden. Eine
 *    Maske, die sie raet, bietet einen Wert an, den der Server ablehnt.
 * 3. **Was gilt, und ist es eine Entscheidung?** Der Unterschied zwischen
 *    „steht auf 15" und „folgt der Vorgabe, die gerade 15 ist" ist der ganze
 *    Witz: Wer nichts entschieden hat, soll einer spaeteren Aenderung der
 *    Vorgabe weiter folgen.
 *
 * {@see ReminderLead::props()} liefert das bereits in genau der Form, die die
 * Maske isst. Dieses Werkzeug ist deshalb duenn — Absicht: Eine zweite
 * Aufbereitung waere eine zweite Wahrheit.
 *
 * ## Es wird nichts kopiert
 *
 * Die Antwort ist eine Auskunft, kein Abgleich. Die Zeile bleibt in der
 * Datenbank des Produkts, dem sie gehoert — eine Kopie im Brain haette ihren
 * eigenen Stand und ihr eigenes Altern.
 */
class ListEventReminderLeadTool extends Tool
{
    use FindetHandelndePerson;

    protected string $description = 'Report how long before an appointment the acting person is reminded in this system: the value in effect, whether they chose it themselves, the product default, and the steps this product offers. Read-only.';

    public function handle(Request $request, ReminderLead $vorlauf): Response
    {
        $person = $this->handelnde();

        $eingeschaltet = (bool) config('calendar.reminders.enabled', false);

        if ($person === null) {
            // Ohne Person gibt es keinen Vorlauf — aber die Stufen und der
            // Schalter sind trotzdem eine ehrliche Auskunft ueber dieses
            // System. `lead_minutes: null` heisst hier NICHT „keine
            // Erinnerung", deshalb sagt `person: false` ausdruecklich, dass
            // die Frage gar nicht beantwortet werden konnte.
            return Response::json([
                'person' => false,
                'enabled' => $eingeschaltet,
                'lead_minutes' => null,
                'eigen' => false,
                'vorgabe' => $vorlauf->vorgabe(),
                'choices' => $vorlauf->auswahl(),
            ]);
        }

        // `array_merge` und NICHT `+`: Der `+`-Operator behaelt den LINKEN
        // Wert. Als das Meldungs-Paket selbst ein `source` lieferte, kippte
        // damit still die Zuordnung im Manager-Posteingang. So fliesst
        // `props()` vollstaendig durch — ein spaeter ergaenztes Feld faellt
        // nicht weg — und die beiden Zusatzfelder gewinnen, weil sie hinten
        // stehen.
        return Response::json(array_merge(
            $vorlauf->props($person),
            ['person' => true, 'enabled' => $eingeschaltet],
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
