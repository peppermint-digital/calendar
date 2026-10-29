<?php

namespace Peppermint\Calendar\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Calendar\Mcp\Concerns\FindetHandelndePerson;
use Peppermint\Calendar\Reminders\ReminderLead;
use Throwable;

/**
 * Den Vorlauf der handelnden Person in DIESEM System festhalten.
 *
 * Die schreibende Haelfte zu {@see ListEventReminderLeadTool}, damit die
 * Pflegemaske nur noch im Brain stehen muss.
 *
 * ## Drei Dinge, die hier anders sind als beim Lesen
 *
 * **1. Ohne Person eine Absage, keine leere Antwort.** Beim Lesen ist „keine
 * Person" eine ehrliche Auskunft. Beim Schreiben waere ein stilles „nichts
 * getan" die schlimmere Variante: Die Maske drueben zeigte dann einen
 * gesetzten Wert, und erinnert wuerde weiter nach dem Alten. Ein
 * Schreibvorgang, der nicht schreiben kann, muss das sagen.
 *
 * **2. Eine Stufe ausserhalb der Auswahl ist ein Fehler, keine Rundung.** Der
 * Aufrufer sitzt in einem anderen System und hat die Stufen ueber das
 * Lesewerkzeug geholt. Stimmt der Wert nicht, ist entweder sein Stand alt
 * oder er hat geraten — beides gehoert gemeldet. Eine stumm auf 15 gerundete
 * 7 waere eine Einstellung, die nicht haelt, was die Maske anzeigt.
 *
 * **3. Es gibt DREI Zustaende, nicht zwei.** Siehe unten; genau daran scheitert
 * eine Maske, die nur einen Zahlenwert kennt.
 *
 * ## „keine" ist nicht „Vorgabe"
 *
 * | `lead` | heisst |
 * |---|---|
 * | `30` | dreissig Minuten vorher |
 * | `none` | keine Erinnerung — eine ENTSCHEIDUNG |
 * | `default` | ich entscheide nicht; die Vorgabe gilt weiter, auch wenn sie sich aendert |
 *
 * `default` ist deshalb NICHT dasselbe wie „auf den heutigen Vorgabewert
 * setzen": Wer zurueckgesetzt hat, folgt einer spaeteren Aenderung weiter.
 * Dafuer gibt es keine Zeile — und deshalb loescht
 * {@see ReminderLead::zuruecksetzen()} sie, statt einen Wert hineinzuschreiben.
 *
 * Und `none` ist nicht `default`: Wer Erinnerungen abgestellt hat, soll von
 * einer spaeteren Anhebung der Vorgabe nicht wieder eingeschaltet werden.
 */
class SetEventReminderLeadTool extends Tool
{
    use FindetHandelndePerson;

    protected string $description = 'Set how long before an appointment the acting person is reminded in this system. Pass minutes (one of the steps reported by list-event-reminder-lead-tool), "none" for no reminder at all, or "default" to clear the choice so the product default applies again.';

    public function handle(Request $request, ReminderLead $vorlauf): Response
    {
        $person = $this->handelnde();

        if ($person === null) {
            // Siehe Klassenkommentar, Punkt 1.
            return Response::error('No acting person for this call. A reminder lead always belongs to exactly one human, so there is nothing to write.');
        }

        $wert = trim((string) ($request->get('lead') ?? ''));

        if ($wert === '') {
            return Response::error(sprintf(
                'Missing "lead". Pass one of: %s, "%s", or "%s".',
                implode(', ', $vorlauf->auswahl()) ?: '(this product offers no steps)',
                self::KEINE,
                self::ZURUECKSETZEN,
            ));
        }

        try {
            if ($wert === self::ZURUECKSETZEN) {
                $vorlauf->zuruecksetzen($person);
            } elseif ($wert === self::KEINE) {
                $vorlauf->setzen($person, null);
            } else {
                if (! ctype_digit($wert) || ! in_array((int) $wert, $vorlauf->auswahl(), true)) {
                    // Siehe Klassenkommentar, Punkt 2. Die Stufen liegen bei —
                    // damit der Aufrufer den Unterschied sieht, statt ein
                    // zweites Werkzeug rufen zu muessen.
                    return Response::error(sprintf(
                        'Unknown lead "%s" in this system. Allowed: %s, "%s", or "%s".',
                        $wert,
                        implode(', ', $vorlauf->auswahl()) ?: '(this product offers no steps)',
                        self::KEINE,
                        self::ZURUECKSETZEN,
                    ));
                }

                $vorlauf->setzen($person, (int) $wert);
            }
        } catch (Throwable $fehler) {
            // Der haeufigste Fall dahinter ist die fehlende Tabelle: Die
            // Migration wird nur VEROEFFENTLICHT, ein Produkt kann sie also
            // noch nicht haben. Lieber eine Absage mit dem Befehl darin als
            // ein gespeicherter Wert, den niemand abholt.
            return Response::error($fehler->getMessage());
        }

        // Den NEUEN Stand zurueckgeben, nicht nur „ok". Der Aufrufer sitzt in
        // einem anderen System und kann sonst nicht wissen, was jetzt gilt —
        // eine zurueckgesetzte Angabe faellt auf die Vorgabe, und die kennt
        // nur dieses Produkt.
        return Response::json(array_merge(
            $vorlauf->props($person),
            ['person' => true, 'enabled' => (bool) config('calendar.reminders.enabled', false)],
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'lead' => $schema->string()
                ->description(sprintf(
                    'Minutes before the start, as reported in "choices" by list-event-reminder-lead-tool; "%s" for no reminder at all; "%s" to clear the choice.',
                    self::KEINE,
                    self::ZURUECKSETZEN,
                ))
                ->required(),
        ];
    }

    /** Das Wort fuer „ich will keine" — eine Entscheidung. */
    private const KEINE = 'none';

    /** Das Wort, mit dem die Entscheidung aufgegeben wird. */
    private const ZURUECKSETZEN = 'default';
}
