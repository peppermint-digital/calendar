<?php

namespace Peppermint\Calendar\Console;

use Illuminate\Console\Command;
use Peppermint\Calendar\Subscriptions\SubscriptionSync;

/**
 * Die abonnierten fremden Kalender holen.
 *
 * ## Was NICHT hier liegt
 *
 * Der Zeitplan. Wie oft geholt wird, ist eine Produktentscheidung — und ein
 * Paket, das sich selbst in den Scheduler eintraegt, nimmt sie weg. Dieselbe
 * Trennung wie beim Zustell-Lauf des Erinnerungs-Pakets.
 *
 * Stuendlich ist der Wert, der sich im Bestand bewaehrt hat: Ein Outlook-Feed
 * ist nicht schnell, und ein Termin, der in zehn Minuten beginnt, war vor einer
 * Stunde schon da. Wer es genauer braucht, laesst oefter laufen — der Abgleich
 * schreibt nur, was sich wirklich geaendert hat.
 */
class SyncSubscriptionsCommand extends Command
{
    protected $signature = 'calendar:sync-subscriptions
                            {--subscription= : Nur dieses Abo (Kennung)}';

    protected $description = 'Holt die abonnierten fremden Kalender und bringt die gespeicherten Termine mit ihnen in Einklang.';

    public function handle(SubscriptionSync $abgleich): int
    {
        $eines = $this->option('subscription');

        if ($eines !== null) {
            $klasse = \Peppermint\Calendar\Subscriptions\Models\CalendarSubscription::class;
            $abo = $klasse::query()->whereKey($eines)->first();

            if ($abo === null) {
                $this->components->error("Kein Abo mit der Kennung [{$eines}].");

                return self::FAILURE;
            }

            return $this->zeigen([
                'abos' => 1,
                ...$abgleich->eines($abo),
                'fehlgeschlagen' => [],
            ] + ['fehler' => null]);
        }

        return $this->zeigen($abgleich->alle());
    }

    /**
     * @param  array<string, mixed>  $bilanz
     */
    private function zeigen(array $bilanz): int
    {
        $this->components->twoColumnDetail('Abos', (string) ($bilanz['abos'] ?? 0));
        $this->components->twoColumnDetail('angelegt', (string) ($bilanz['angelegt'] ?? 0));
        $this->components->twoColumnDetail('geaendert', (string) ($bilanz['geaendert'] ?? 0));
        $this->components->twoColumnDetail('weggenommen', (string) ($bilanz['weggenommen'] ?? 0));

        // Ein einzelner Fehlschlag darf den Lauf nicht roetlich faerben — aber
        // er gehoert GENANNT, mit Namen. Ein Abo, das seit Wochen scheitert,
        // sieht in einer Zahl wie ein Rundungsfehler aus.
        $fehler = $bilanz['fehler'] ?? null;

        if (is_string($fehler)) {
            $this->components->warn('Fehlgeschlagen: '.$fehler);
        }

        foreach ((array) ($bilanz['fehlgeschlagen'] ?? []) as $abo => $grund) {
            $this->components->warn("Abo {$abo}: {$grund}");
        }

        return self::SUCCESS;
    }
}
