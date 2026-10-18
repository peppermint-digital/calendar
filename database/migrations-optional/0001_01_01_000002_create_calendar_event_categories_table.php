<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wo die Zuordnung Termin -> Kategorie liegt.
 *
 * ## Warum das Paket sie jetzt selbst haelt
 *
 * Bis v0.60.0 hiess es: Die Anwendung entscheidet, wo ihre Zuordnung liegt —
 * im Profil ihrer Terminart oder in einer eigenen Spalte. Gemessen am
 * 18.10.2026 war das Ergebnis, dass sie **nirgends** funktionierte:
 *
 *     Brain       7 Kategorien, 0 Verwendungen
 *                 profile_private.category_id — 31 Zeilen, keine belegt
 *                 PrivateEventKind::usesCategories() = false
 *     Manager     business bejaht usesCategories() — ohne Spalte dafuer
 *     CRM         aus
 *     Verwaltung  aus
 *
 * Einer bejaht die Funktion ohne Ablage, einer hat die Ablage und bejaht sie
 * nicht. Vier Produkte mal je Terminart eine eigene Spalte waere genau die
 * Zersplitterung, die hier abgebaut wird — und jede dieser Spalten waere die,
 * die beim naechsten Produkt fehlt.
 *
 * ## Warum eine Verknuepfungstabelle und keine Spalte
 *
 * Weil die Auswertung schon mehr annimmt: Der `CategoryReportAssembler`
 * zaehlt einen Termin in JEDER seiner Kategorien voll, waehrend die Gesamtzeit
 * ihn einmal zaehlt — und weist das in der Darstellung auch aus. Mit einer
 * Einzelspalte waere diese Rechnung von Anfang an sinnlos gewesen.
 *
 * ## Warum optional
 *
 * Dieselbe Begruendung wie bei der Kategorienliste selbst: Wer keine
 * Kategorien fuehrt, soll keine leere Tabelle bekommen. Beide kommen ueber
 * denselben Schalter:
 *
 *     php artisan vendor:publish --tag=calendar-categories
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = $this->tabelle();

        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $table) {
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();

            // Zweimal dieselbe Kategorie am selben Termin ist keine Aussage,
            // sondern ein Doppelklick. Die Auswertung wuerde ihn als doppelte
            // Zeit lesen.
            $table->primary(['event_id', 'category_id'], 'cal_event_categories_pk');

            // „Welche Termine tragen diese Kategorie" ist die Frage der
            // Auswertung und des Filters — ohne den Index liest sie die ganze
            // Tabelle.
            $table->index('category_id', 'cal_event_categories_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tabelle());
    }

    private function tabelle(): string
    {
        return config('calendar.tables.event_categories', 'calendar_event_categories');
    }
};
