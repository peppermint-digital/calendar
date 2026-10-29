<?php

namespace Peppermint\Calendar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Calendar\Reminders\ReminderLead;

/**
 * Die Einstellung „wie lange vor einem Termin will ich es wissen" —
 * Serverseite.
 *
 * Duenn wie das Gegenstueck in `peppermint/notifications`: Die Entscheidungen
 * liegen in {@see ReminderLead}, hier wird nur geprueft und durchgereicht.
 *
 * ## Warum der Endpunkt im Paket liegt und die Maske nicht
 *
 * Paketvertrag Regel 8: Oberflaeche bleibt im Produkt, und sie sieht dort
 * jeweils anders aus. Die drei Zeilen Pruefung dahinter saehen in jedem
 * Produkt gleich aus — das ist Pruefrage 3, und das Meldungs-Paket hat sie
 * fuer seine Einstellungen genauso beantwortet.
 */
class ReminderLeadController
{
    public function __construct(private readonly ReminderLead $vorlauf) {}

    /** Was gilt, was die Vorgabe ist, und was zur Wahl steht. */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->vorlauf->props($request->user()));
    }

    /**
     * Die Entscheidung speichern.
     *
     * Drei Faelle, und sie sind wirklich drei:
     *
     * | Anfrage | heisst |
     * |---|---|
     * | `lead_minutes: 30` | „dreissig Minuten vorher" |
     * | `lead_minutes: null` | „keine Erinnerung" — eine Entscheidung |
     * | `reset: true` | „ich entscheide nicht" — die Vorgabe gilt weiter |
     *
     * Der dritte ist nicht dasselbe wie „auf den heutigen Vorgabewert
     * setzen": Wer zurueckgesetzt hat, folgt einer spaeteren Aenderung der
     * Vorgabe weiter.
     */
    public function update(Request $request): JsonResponse
    {
        // Gegen die ANGEBOTENEN Stufen, nicht gegen „irgendeine Zahl". Sonst
        // steht morgen eine 7 in der Spalte, die keine Maske anzeigen kann —
        // und niemand weiss, wie sie hineingekommen ist.
        $erlaubt = $this->vorlauf->auswahl();

        $geprueft = $request->validate([
            'lead_minutes' => ['nullable', 'integer', 'in:'.implode(',', $erlaubt)],
            'reset' => ['sometimes', 'boolean'],
        ]);

        if ($geprueft['reset'] ?? false) {
            $this->vorlauf->zuruecksetzen($request->user());
        } else {
            $this->vorlauf->setzen($request->user(), $geprueft['lead_minutes'] ?? null);
        }

        return response()->json(['ok' => true] + $this->vorlauf->props($request->user()));
    }
}
