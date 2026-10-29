<?php

namespace Peppermint\Calendar\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Peppermint\Calendar\Mcp\Concerns\FindetHandelndePerson;
use Peppermint\Calendar\Subscriptions\Models\SubscribedEvent;
use Peppermint\Calendar\Support\CalendarWindow;

/**
 * Die Termine der abonnierten Kalender der handelnden Person.
 *
 * ## Warum dieses Werkzeug gebraucht wird
 *
 * Seit dem 29.10.2026 liegen die Abos in AI Brain und nicht mehr in den
 * Produkten. Damit sie dort weiter im Kalender STEHEN, fragt
 * {@see \Peppermint\Calendar\Sources\BrainSubscribedEventSource} hier nach —
 * dasselbe Verhaeltnis wie bei den privaten Terminen, die denselben Weg im
 * September gegangen sind (#3529, #4823).
 *
 * ## Warum es im Paket liegt und nicht im Brain
 *
 * Weil es ausschliesslich ueber Paket-Modelle liest. Ein Produkt, das eines
 * Tages ebenfalls Abos haelt, kann es anmelden, ohne vierzig Zeilen
 * nachzubauen — und das Gegenstueck, die Quelle, liegt ohnehin hier.
 *
 * ## Rein lesend, und der Schutz ist strenger als eine Policy
 *
 * Es gibt KEINEN Parameter, mit dem man in die Abos einer anderen Person saehe.
 * Ohne nachgewiesene Person kommt eine leere Liste statt irgendeiner — und das
 * ist hier keine Hoeflichkeit: In einem abonnierten Privatkalender steht, was
 * jemand privat vorhat. Der Rueckfall „keine Identitaet, also alles zeigen"
 * war im Manager Bug #657.
 */
class ListSubscribedEventsTool extends Tool
{
    use FindetHandelndePerson;

    protected string $description = 'List the acting person\'s events from their subscribed external calendars (Outlook, Google, any ICS feed) within a date range. Read-only.';

    public function handle(Request $request): Response
    {
        $person = $this->handelnde();

        if ($person === null) {
            // Leer und nicht „alles": siehe Klassenkommentar.
            return Response::json(['events' => [], 'timezone' => $this->zone()]);
        }

        $fenster = CalendarWindow::resolve(
            $request->get('range'),
            $request->get('from'),
            $request->get('to'),
        );

        $termine = SubscribedEvent::query()
            ->with('calendar')
            ->whereHas(
                'calendar',
                fn ($abfrage) => $abfrage
                    ->where('user_id', $this->kennung($person))
                    ->where('is_active', true),
            )
            // `CalendarWindow` liefert die Grenzen schon als Tagesanfang und
            // Tagesende in der Zone der Anwendung — nicht noch einmal runden.
            ->whereBetween('start_datetime', [$fenster->from, $fenster->to])
            ->orderBy('start_datetime')
            ->get();

        return Response::json([
            'events' => $termine->map(fn (SubscribedEvent $termin): array => [
                'id' => $termin->getKey(),
                'uid' => $termin->uid,
                'title' => $termin->title,
                // Mit Zone, nicht als Wanduhrzeit: Die Gegenseite ist ein
                // anderes Produkt mit einer eigenen `app.timezone`, und eine
                // Uhrzeit ohne Zone ist an dieser Naht schon zweimal um zwei
                // Stunden verrutscht (Bug #995, #1003).
                'starts_at' => $termin->start_datetime?->toIso8601String(),
                'ends_at' => $termin->end_datetime?->toIso8601String(),
                'all_day' => (bool) $termin->all_day,
                'location' => $termin->location,
                // Der Name des Abos, damit die Oberflaeche drueben „Outlook"
                // neben den Termin schreiben kann statt alle Abos in einen Topf
                // zu werfen.
                'subscription' => $termin->calendar?->name,
            ])->all(),
            'timezone' => $this->zone(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'range' => $schema->string()->description('today | week | month. Omit when from/to are given.'),
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.'),
            'to' => $schema->string()->description('End date, YYYY-MM-DD.'),
        ];
    }

    /**
     * Die Kennung der Person — egal, in welcher Gestalt sie hereinkommt.
     *
     * `Authenticatable` ist mit Absicht dabei und nicht nur `Model`: Ein
     * Produkt kann eine Wache fuehren, deren Nutzer kein Eloquent-Modell ist.
     * Ein `getKey()` ohne Rueckfall waere dort ein Abbruch mitten in einem
     * Kalenderaufruf — dieselbe Stelle, an der {@see \Peppermint\Calendar\Reminders\ReminderLead}
     * schon einmal zu eng war.
     */
    private function kennung(mixed $person): int|string|null
    {
        if ($person instanceof \Illuminate\Database\Eloquent\Model) {
            return $person->getKey();
        }

        if ($person instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            return $person->getAuthIdentifier();
        }

        return null;
    }

    private function zone(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }
}
