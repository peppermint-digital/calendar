<?php

namespace Peppermint\Calendar\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Models\CalendarCategory;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Kategorienliste pflegen.
 *
 * ## Warum das im Paket liegt
 *
 * Die Liste ist in vier Systemen dieselbe, und ihre Pflege war in keinem
 * vorhanden: Der Manager hat eine Verwaltungsmaske fuer seine eigene Tabelle,
 * das AI Brain hat seine sieben Kategorien per Wanderung bekommen, CRM und
 * Verwaltung hatten die Funktion aus. Vier Masken fuer dieselbe Tabelle waeren
 * vier Gelegenheiten, die Regeln verschieden zu bauen — und die Regeln sind
 * hier der ganze Punkt (#8178).
 *
 * Die Maske selbst kommt aus dem Paket, eingebunden wird sie dort, wo sie
 * hingehoert: im Einstellungsmenue des AI Brain, wenn es das Brain gibt, und
 * im jeweiligen Produkt, wenn nicht.
 *
 * ## Was die Autorisierung macht und was nicht
 *
 * Das Paket kennt nur den NAMEN der Berechtigung, nicht ihre Bedeutung —
 * `calendar.categories.manage_ability`. Ohne konfigurierten Namen wird nicht
 * gefragt: Ein Paket, das sich selbst Rechte ausdenkt, waere schlimmer als
 * eines, das keine prueft. Die Adressen und die Anmeldeschranke legt das
 * Produkt, wie bei {@see \Peppermint\Calendar\Http\CalendarRoutes}.
 *
 * ## Warum Loeschen die Ausnahme ist und Deaktivieren die Regel
 *
 * An einer Kategorie haengen Termine. Eine geloeschte Kategorie nimmt deren
 * Einordnung mit, und das faellt nicht beim Loeschen auf, sondern Wochen
 * spaeter in der Auswertung — als fehlende Stunden, die niemand erklaeren
 * kann. Deshalb: Geloescht wird nur, was NICHTS traegt. Alles andere wird
 * deaktiviert, verschwindet damit aus jeder Auswahl und behaelt seine
 * Geschichte.
 *
 * Gemessen am 18.10.2026 im Manager: 206 von 359 Terminen tragen eine
 * Kategorie, 199 davon dieselbe. Ein unbedachtes Loeschen haette dort auf
 * einen Schlag 199 Einordnungen entfernt.
 */
class CategoryAdminController
{
    public function __construct(private readonly CategoryRegistry $registry) {}

    /**
     * Die ganze Liste, auch die inaktiven.
     *
     * Absichtlich nicht `forUser()`: Die Pflegemaske muss zeigen, was es gibt,
     * nicht was gerade auswaehlbar ist. Eine deaktivierte Kategorie, die in
     * ihrer eigenen Verwaltung unsichtbar ist, kann niemand wieder
     * einschalten.
     */
    public function index(Request $request): JsonResponse
    {
        $this->darfPflegen();

        $kategorien = CalendarCategory::query()
            ->orderBy(CalendarCategory::column('sort_order'))
            ->orderBy(CalendarCategory::column('label'))
            ->get();

        return response()->json([
            'categories' => $kategorien->map(fn (CalendarCategory $k) => $this->alsZeile($k))->all(),
            'mode' => $this->registry->mode()->value,
            'kinds' => $this->artenSchluessel(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->darfPflegen();

        $daten = $this->geprueft($request);

        $slug = $this->registry->slug($daten['label']);

        if ($slug === '') {
            abort(422, 'Der Name ergibt keinen Slug.');
        }

        // Der Slug ist der Schluessel, auf den sich die Systeme einigen — zwei
        // Kategorien mit demselben Slug waeren systemuebergreifend eine.
        $schon = CalendarCategory::query()
            ->where(CalendarCategory::column('slug'), $slug)
            ->exists();

        if ($schon) {
            abort(422, 'Diesen Slug gibt es schon: '.$slug);
        }

        $kategorie = CalendarCategory::query()->create(CalendarCategory::onlyExistingColumns([
            CalendarCategory::column('slug') => $slug,
            CalendarCategory::column('label') => $daten['label'],
            CalendarCategory::column('colour') => $daten['colour'] ?? null,
            CalendarCategory::column('sort_order') => $daten['sort_order'] ?? 0,
            CalendarCategory::column('is_active') => $daten['is_active'] ?? true,
            CalendarCategory::column('kind') => $daten['kind'] ?? null,
            CalendarCategory::column('owner_id') => null,
        ]));

        return response()->json(['category' => $this->alsZeile($kategorie)], 201);
    }

    /**
     * Umbenennen, umfaerben, umsortieren, ein- und ausschalten.
     *
     * Der SLUG bleibt, auch wenn das Etikett sich aendert. Er ist der
     * Schluessel, auf den sich vier Systeme geeinigt haben — wer ihn beim
     * Umbenennen mitzieht, loest die Verbindung still auf: Die Kategorie heisst
     * dann woanders anders, und das Querfiltern findet sie nicht mehr.
     */
    public function update(Request $request, int $category): JsonResponse
    {
        $this->darfPflegen();

        $kategorie = CalendarCategory::query()->find($category);

        if ($kategorie === null) {
            throw new NotFoundHttpException('Kategorie nicht gefunden.');
        }

        $daten = $this->geprueft($request);

        $kategorie->fill(CalendarCategory::onlyExistingColumns(array_filter([
            CalendarCategory::column('label') => $daten['label'] ?? null,
            CalendarCategory::column('colour') => $daten['colour'] ?? null,
            CalendarCategory::column('sort_order') => $daten['sort_order'] ?? null,
            CalendarCategory::column('kind') => $daten['kind'] ?? null,
        ], fn ($wert) => $wert !== null)));

        if (array_key_exists('is_active', $daten)) {
            $kategorie->setAttribute(CalendarCategory::column('is_active'), (bool) $daten['is_active']);
        }

        $kategorie->save();

        return response()->json(['category' => $this->alsZeile($kategorie->refresh())]);
    }

    /**
     * Loeschen — nur, wenn nichts daran haengt.
     *
     * Antwortet mit 409 und der Anzahl, statt stillschweigend zu deaktivieren:
     * Wer loeschen wollte, soll erfahren, dass es nicht ging und warum. Eine
     * Maske, die „erledigt" sagt und etwas anderes getan hat, ist schlimmer
     * als eine Fehlermeldung.
     */
    public function destroy(int $category): JsonResponse
    {
        $this->darfPflegen();

        $kategorie = CalendarCategory::query()->find($category);

        if ($kategorie === null) {
            throw new NotFoundHttpException('Kategorie nicht gefunden.');
        }

        $anzahl = $this->verwendungen($kategorie);

        if ($anzahl > 0) {
            return response()->json([
                'deleted' => false,
                'in_use' => $anzahl,
                'message' => "Diese Kategorie haengt an {$anzahl} Terminen. "
                    .'Deaktivieren statt loeschen — dann verschwindet sie aus jeder Auswahl '
                    .'und die Einordnung bleibt erhalten.',
            ], 409);
        }

        $kategorie->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Wie viele Termine diese Kategorie tragen.
     *
     * Gefragt wird die Zuordnungstabelle des Pakets. Haelt eine Terminart ihre
     * Kategorie woanders — wie `BusinessKind` im Manager, der sie im Profil
     * fuehrt —, dann weiss das nur sie, und das Paket kann es nicht wissen.
     * Deshalb ist diese Zahl eine UNTERGRENZE, und darum verweigert die
     * Loeschung bei jedem Treffer statt abzuwaegen.
     */
    private function verwendungen(CalendarCategory $kategorie): int
    {
        if (! $this->registry->assignmentsAvailable()) {
            return 0;
        }

        return $kategorie->events()->count();
    }

    /** @return array<string, mixed> */
    private function geprueft(Request $request): array
    {
        return $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'colour' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
            // Nur Arten, die es wirklich gibt: Ein Tippfehler hier macht eine
            // Kategorie unsichtbar, ohne dass irgendwo ein Fehler erscheint.
            'kind' => ['sometimes', 'nullable', 'string', Rule::in($this->artenSchluessel())],
        ]);
    }

    /** @return array<int, string> */
    private function artenSchluessel(): array
    {
        return collect(app(\Peppermint\Calendar\Kinds\EventKindRegistry::class)->all())
            ->filter(fn ($art) => $art->usesCategories())
            ->map(fn ($art) => $art->key())
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function alsZeile(CalendarCategory $kategorie): array
    {
        return [
            'id' => $kategorie->getKey(),
            'slug' => $kategorie->field('slug'),
            'label' => $kategorie->field('label'),
            'colour' => $kategorie->field('colour'),
            'sort_order' => $kategorie->field('sort_order'),
            'is_active' => (bool) $kategorie->field('is_active'),
            'kind' => $kategorie->field('kind'),
            'in_use' => $this->verwendungen($kategorie),
        ];
    }

    private function darfPflegen(): void
    {
        if (! $this->registry->enabled()) {
            throw new NotFoundHttpException('Kategorien sind in diesem System aus.');
        }

        $ability = config('calendar.categories.manage_ability');

        if (is_string($ability) && $ability !== '' && Gate::denies($ability)) {
            throw new AccessDeniedHttpException('Keine Berechtigung, die Kategorien zu pflegen.');
        }
    }
}
