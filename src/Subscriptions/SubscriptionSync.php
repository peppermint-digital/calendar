<?php

namespace Peppermint\Calendar\Subscriptions;

use Peppermint\Calendar\Subscriptions\Models\CalendarSubscription;
use Peppermint\Calendar\Subscriptions\Models\SubscribedEvent;
use Throwable;

/**
 * Die gespeicherten Termine eines Abos mit dem Feed in Einklang bringen.
 *
 * ## Warum das ein ABGLEICH ist und kein Neuanlegen
 *
 * Hier liegt der Fehler, wegen dem diese Klasse nicht verschoben, sondern neu
 * geschrieben wurde. Die Manager-Fassung tat stuendlich das:
 *
 * ```php
 * $calendar->events()->delete();                 // ALLE Termine weg
 * foreach ($events as $e) { ...updateOrCreate() } // und neu anlegen
 * ```
 *
 * Fuer die Anzeige ist das nur haesslich: ein Fenster, in dem der Kalender
 * leer ist, und neue Kennungen fuer dieselben Termine.
 *
 * Zusammen mit ERINNERUNGEN ist es ein Schaden. Eine Erinnerung haengt ueber
 * `subject_id` an der Zeile. Werden die Zeilen jede Stunde geloescht und neu
 * angelegt, raeumt der Erinnerungs-Abgleich sie als verwaist weg und legt sie
 * danach neu an — mit zurueckgesetztem `delivered_at`. Ergebnis: **jede Stunde
 * dieselbe Erinnerung erneut**, bis der Termin vorbei ist. Bei vierstelligen
 * Terminzahlen ist das die Flut, vor der jeder Auftrag warnt.
 *
 * Also: anlegen, was neu ist; mitfuehren, was sich geaendert hat; wegnehmen,
 * was der Feed nicht mehr nennt. Dieselbe Haltung wie der Erinnerungs-Abgleich
 * selbst.
 *
 * ## Was bei einem Fehlschlag passiert
 *
 * NICHTS wird weggenommen. Antwortet der Feed nicht, bleibt der letzte gute
 * Stand stehen und der Fehler landet am Abo. Die Alternative waere ein
 * Kalender, der bei jeder Netzstoerung leer ist — und ein leerer Kalender sieht
 * aus wie „keine Termine" und nicht wie „ich konnte nicht nachsehen".
 */
class SubscriptionSync
{
    public function __construct(private readonly IcsFeed $feed) {}

    /**
     * Ein Abo abgleichen.
     *
     * @return array{angelegt: int, geaendert: int, weggenommen: int, fehler: string|null}
     */
    public function eines(CalendarSubscription $abo): array
    {
        try {
            $ausDemFeed = $this->feed->holen((string) $abo->url);
        } catch (Throwable $fehler) {
            $abo->forceFill(['last_error' => $fehler->getMessage()])->save();

            return ['angelegt' => 0, 'geaendert' => 0, 'weggenommen' => 0, 'fehler' => $fehler->getMessage()];
        }

        $bilanz = ['angelegt' => 0, 'geaendert' => 0, 'weggenommen' => 0, 'fehler' => null];

        /** @var array<string, SubscribedEvent> $vorhandene */
        $vorhandene = $abo->events()->get()->keyBy('uid')->all();
        $gesehen = [];

        foreach ($ausDemFeed as $daten) {
            $uid = (string) $daten['uid'];
            $gesehen[$uid] = true;

            $zeile = $vorhandene[$uid] ?? null;

            if ($zeile === null) {
                $abo->events()->create($daten);
                $bilanz['angelegt']++;

                continue;
            }

            // Nur schreiben, wenn sich wirklich etwas geaendert hat. Ein
            // `save()` auf jede Zeile waere bei vierstelligen Terminzahlen
            // stuendlich eine vierstellige Zahl Schreibvorgaenge — und jedes
            // `updated_at` sieht danach wie eine Aenderung aus.
            $neu = [];

            foreach (['source_uid', 'title', 'description', 'location', 'all_day'] as $feld) {
                if (($daten[$feld] ?? null) !== $zeile->{$feld}) {
                    $neu[$feld] = $daten[$feld] ?? null;
                }
            }

            foreach (['start_datetime', 'end_datetime'] as $feld) {
                if ($zeile->{$feld} === null || ! $zeile->{$feld}->equalTo($daten[$feld])) {
                    $neu[$feld] = $daten[$feld];
                }
            }

            if ($neu !== []) {
                $zeile->forceFill($neu)->save();
                $bilanz['geaendert']++;
            }
        }

        // Wegnehmen, was der Feed nicht mehr nennt — aber nur, wenn er
        // ueberhaupt etwas genannt hat. Ein Feed, der heute leer antwortet,
        // ohne HTTP-Fehler, kommt vor (ein geleerter Kalender, ein Anbieter mit
        // Schluckauf); den ganzen Bestand daraufhin wegzuraeumen waere der
        // teuerste moegliche Schluss aus der duennsten Auskunft.
        if ($ausDemFeed !== []) {
            $wegzunehmen = array_values(array_diff(array_keys($vorhandene), array_keys($gesehen)));

            if ($wegzunehmen !== []) {
                $bilanz['weggenommen'] = $abo->events()->whereIn('uid', $wegzunehmen)->delete();
            }
        }

        $abo->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return $bilanz;
    }

    /**
     * Alle aktiven Abos.
     *
     * @return array{abos: int, angelegt: int, geaendert: int, weggenommen: int, fehlgeschlagen: array<string, string>}
     */
    public function alle(): array
    {
        $gesamt = ['abos' => 0, 'angelegt' => 0, 'geaendert' => 0, 'weggenommen' => 0, 'fehlgeschlagen' => []];

        CalendarSubscription::query()->active()->get()->each(function (CalendarSubscription $abo) use (&$gesamt): void {
            $gesamt['abos']++;

            $bilanz = $this->eines($abo);

            if ($bilanz['fehler'] !== null) {
                // Mit NAME und Kennung, nicht nur gezaehlt: Ein Abo, das seit
                // Wochen scheitert, sieht in einer Zahl wie ein Rundungsfehler
                // aus.
                $gesamt['fehlgeschlagen'][$abo->getKey().' ('.$abo->name.')'] = $bilanz['fehler'];

                return;
            }

            $gesamt['angelegt'] += $bilanz['angelegt'];
            $gesamt['geaendert'] += $bilanz['geaendert'];
            $gesamt['weggenommen'] += $bilanz['weggenommen'];
        });

        return $gesamt;
    }
}
