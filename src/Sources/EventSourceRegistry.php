<?php

namespace Peppermint\Calendar\Sources;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;

class EventSourceRegistry
{
    /** @var array<string, EventSource> */
    protected array $sources = [];

    public function register(EventSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /** @return array<string, EventSource> */
    public function all(): array
    {
        return $this->sources;
    }

    /** @return array<string, EventSource> */
    public function available(): array
    {
        return array_filter($this->sources, fn (EventSource $source) => $source->isAvailable());
    }

    /**
     * Die Quellen, in denen man anlegen darf.
     *
     * Eine Oberflaeche fragt danach, statt selbst zu pruefen — wer die Pruefung
     * an jeder Bedienstelle wiederholt, vergisst sie irgendwo.
     *
     * @return array<string, WritableEventSource>
     */
    public function writable(): array
    {
        return array_filter(
            $this->available(),
            fn (EventSource $source) => $source instanceof WritableEventSource && $source->isWritable(),
        );
    }

    /**
     * Die Ziele fuer die Anlege-Maske — beschreibbare UND ausgefallene.
     *
     * ## Warum das hier steht und nicht im Produkt
     *
     * Weil die Antwort hier schon stand und niemand sie gefragt hat.
     * {@see writable()} gibt es seit Langem, mit der Begruendung im Docblock
     * direkt darueber — und gemessen am 12.11.2026 rief es NIEMAND: nicht
     * dieses Paket, nicht eines der drei Produkte, nicht die Testreihe. Null
     * Treffer. Daneben stand `beschreibbareZiele()` als Handkopie in DREI
     * Produkten (Manager, CRM, Verwaltung), die stattdessen selbst mit
     * `method_exists()` filterte.
     *
     * Das ist kein „die Antwort ins Paket ziehen", sondern „die Antwort, die
     * im Paket steht, endlich fragen". Und es ist deshalb auch kein neuer Weg:
     * Der Weg ist gebaut, er war nur nicht angeschlossen.
     *
     * Der Autor von `writable()` hat die Fehlerart, die dann eintrat, VORHER in
     * den Docblock geschrieben — „wer die Pruefung an jeder Bedienstelle
     * wiederholt, vergisst sie irgendwo". Die Vorhersage stand daneben,
     * waehrend es passierte. Ein Hinweis an einer Stelle, an der niemand
     * vorbeikommt, ist deshalb kein Ersatz fuer eine Methode, die man
     * aufrufen MUSS, um an die Antwort zu kommen.
     *
     * ## Warum ein ausgefallenes Ziel `writable: false` behaelt
     *
     * Es waere bequem, eine unerreichbare Quelle mit `writable: true`
     * hereinzugeben — dann faende sie der Vorlade-Weg der Oberflaeche von
     * selbst und landete ueber den fehlgeschlagenen Artenabruf in der
     * Ausfall-Liste. Das waere gelogen, und zwar folgenreich: Sie wuerde
     * waehlbar. Jemand waehlt sie aus, und das Anlegen scheitert erst beim
     * Speichern.
     *
     * Ein Knopf, der drueben scheitert, ist schlechter als kein Knopf
     * (Wiki #595) — und damit schlechter als der Zustand VOR der Behebung, in
     * dem die Quelle wenigstens nur lautlos verschwand. Deshalb eine eigene
     * Markierung: `unreachable: true`, `writable: false`.
     *
     * ## Was die Oberflaeche damit macht
     *
     * `writable` fuehrt in die Zielauswahl, `unreachable` in den Hinweis am
     * Artenschritt („Terminarten dieser Systeme fehlen in der Liste — die
     * uebrigen sind vollstaendig."). Beides sind getrennte Props, weil es
     * getrennte Aussagen sind.
     *
     * ## Was NICHT hereinkommt
     *
     * Eine Quelle, die ordentlich „nein" sagt — nicht freigeschaltet, kein
     * Recht, nicht zustaendig. Die soll verschwinden, und das ist nach
     * Bug #1417 ausdruecklich so gewollt: Behoben wird, dass ein AUSFALL
     * aussieht wie ein Nein, nicht dass ein Nein verschwindet.
     *
     * @return array<int, array{key: string, label: string, writable: bool, unreachable: bool}>
     */
    public function ziele(): array
    {
        $ziele = [];

        // Ueber `all()` und nicht `available()`: Eine ausgefallene Quelle ist
        // per `isAvailable()` schon `false` und waere aus `available()`
        // heraus, BEVOR man sie fragen kann. Genau daran lag Bug #1417 — die
        // Information wurde verworfen, ehe irgendwer sie lesen konnte.
        foreach ($this->sources as $key => $source) {
            if ($source instanceof WritableEventSource && $source->isWritable()) {
                $ziele[] = [
                    'key' => $key,
                    'label' => $source->label(),
                    'writable' => true,
                    'unreachable' => false,
                ];

                continue;
            }

            // Nur wer den Ausfall-Vertrag erfuellt UND ihn bejaht. Eine Quelle
            // ohne den Vertrag sagt damit „ich weiss es nicht" — und das ist
            // bei einem fremden Produkt die Wahrheit (#1416).
            if ($source instanceof MeldetAusfall && $source->istAusgefallen()) {
                $ziele[] = [
                    'key' => $key,
                    'label' => $source->label(),
                    'writable' => false,
                    'unreachable' => true,
                ];
            }
        }

        return $ziele;
    }

    /**
     * Collects events from every available source.
     *
     * A source that fails does not take the calendar with it: the other events
     * are still shown and the failure is logged. A calendar that goes blank
     * because one remote system is slow is worse than one that is incomplete —
     * and the incompleteness is visible, the blank page is not explicable.
     *
     * @param  array<int, string>  $only  restrict to these source keys, empty = all
     * @return array<int, ExternalEvent>
     */
    public function collect(int $userId, DateTimeInterface $from, DateTimeInterface $to, array $only = []): array
    {
        $events = [];

        foreach ($this->available() as $key => $source) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            try {
                foreach ($source->events($userId, $from, $to) as $event) {
                    $events[] = $event;
                }
            } catch (\Throwable $e) {
                Log::warning('calendar.source_failed', [
                    'source' => $key,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        usort($events, fn (ExternalEvent $a, ExternalEvent $b) => $a->startsAt <=> $b->startsAt);

        return $events;
    }
}
