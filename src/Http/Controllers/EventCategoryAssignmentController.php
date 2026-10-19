<?php

namespace Peppermint\Calendar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Categories\EventCategories;
use Peppermint\Calendar\Exceptions\CategoryNotAllowed;
use Peppermint\Calendar\Models\CalendarEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Kategorien EINES Termins setzen.
 *
 * ## Warum es diesen Weg gibt
 *
 * Gemessen am 19.10.2026 im Manager: 483 von 1211 Terminen sind Zeitblöcke an
 * einer Aufgabe — 40 Prozent des Kalenders. Keiner von ihnen kann eine
 * Kategorie tragen, und damit faellt dieser Teil in der Auswertung komplett
 * unter „ohne Kategorie".
 *
 * Der naheliegende Weg waere gewesen, die Kategorie aus der Aufgabe
 * ABZULEITEN. Das wurde gemessen und verworfen: `tasks.kind` ist fast konstant
 * (977 „vorgang" gegen 9 „routine"), und die Kategoriefelder der Projekte sind
 * leer — von 307 Bloecken haetten 11 darueber etwas bekommen. Eine Ableitung
 * haette die restlichen 296 mit einer Kategorie gefuellt, die niemand gewaehlt
 * hat, und die Auswertung haette praezise ausgesehen, obwohl sie geraten war.
 * Das ist schlechter als eine ehrliche Luecke, weil man es nicht sieht.
 *
 * Also von Hand. Und weil ein Zeitblock durch ZIEHEN entsteht und nicht ueber
 * eine Maske ({@see \Peppermint\Calendar\Kinds\PlannedBlockKind::isUserCreatable()}),
 * gibt es dafuer keinen Dialog, in dem man es mitwaehlen koennte — deshalb
 * dieser eigene, kleine Weg aus der Detailansicht heraus.
 *
 * ## Was er prueft
 *
 * **Sichtbarkeit.** Wer den Termin nicht sehen darf, darf ihn auch nicht
 * etikettieren — gefragt wird derselbe `visibleTo`-Filter, den die Anzeige
 * benutzt, damit es nicht zwei Antworten auf dieselbe Frage gibt.
 *
 * **Eigentum.** Sehen heisst nicht aendern: Ein Teilnehmer sieht den Termin,
 * aber die Einordnung gehoert dem, dem der Termin gehoert. Sonst traegt die
 * Auswertung einer Person die Etiketten einer anderen.
 *
 * Die Eingrenzung auf die Terminart prueft {@see EventCategories::set()} —
 * dort, wo sie fuer jeden Aufrufer gilt, nicht nur fuer diesen.
 */
class EventCategoryAssignmentController
{
    public function __construct(
        private readonly EventCategories $zuordnung,
        private readonly CategoryRegistry $registry,
    ) {}

    public function update(Request $request, int|string $event): JsonResponse
    {
        if (! $this->registry->enabled()) {
            throw new NotFoundHttpException('Kategorien sind in diesem System aus.');
        }

        $daten = $request->validate([
            'categories' => ['present', 'array'],
            'categories.*' => ['string', 'max:100'],
        ]);

        $userId = (int) $request->user()->id;

        $termin = CalendarEvent::query()
            ->visibleTo($userId)
            ->whereKey($event)
            ->first();

        if ($termin === null) {
            throw new NotFoundHttpException('Termin nicht gefunden.');
        }

        // Sehen heisst nicht aendern. Siehe Klassenkommentar.
        if ((int) $termin->field('owner_id') !== $userId) {
            throw new AccessDeniedHttpException('Nur wer den Termin besitzt, ordnet ihn ein.');
        }

        try {
            $this->zuordnung->set($termin, $daten['categories'], $userId);
        } catch (CategoryNotAllowed $fehler) {
            // 422 und nicht 403: Es fehlt kein Recht, die Angabe passt nicht —
            // eine Kategorie, die diese Terminart nicht anbietet, oder eine,
            // die es nicht gibt.
            abort(422, $fehler->getMessage());
        }

        return response()->json([
            'categories' => $this->zuordnung->slugsOf($termin->refresh()),
            'labels' => $termin->kindDefinition()->categories($termin),
        ]);
    }
}
