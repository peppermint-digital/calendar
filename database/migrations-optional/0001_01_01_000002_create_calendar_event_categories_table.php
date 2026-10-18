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
 * 18.10.2026 ueber alle vier Produkte sah das so aus:
 *
 *     Manager     funktioniert — `BusinessKind` ueberschreibt `categories()`
 *                 und liest `profile_business.category_id`; 206 von 359
 *                 Terminen tragen eine Kategorie (199 „Peppermint",
 *                 7 „CrewTex")
 *     Brain       7 Kategorien, 0 Verwendungen
 *                 profile_private.category_id — 31 Zeilen, keine belegt
 *                 PrivateEventKind::usesCategories() = false
 *     CRM         aus
 *     Verwaltung  aus
 *
 * ## Eine Korrektur, die hierher gehoert
 *
 * In der ersten Fassung dieses Kommentars stand, die Funktion habe NIRGENDS
 * funktioniert und der Manager bejahe sie „ohne Spalte dafuer". Das war
 * falsch, und der Fehler ist lehrreich: Gemessen war eine lokale
 * Entwicklungsdatenbank, in der die Spalte fehlte und die Tabelle leer war.
 * In Produktion gibt es beides, und 206 Termine haengen daran. Eine
 * Begruendung, die auf Dev gemessen und als Produktion aufgeschrieben wird,
 * ist eine falsche Begruendung — auch wenn die Entscheidung am Ende richtig
 * bleibt.
 *
 * Richtig bleibt sie, weil drei von vier Produkten die Zuordnung nicht hatten
 * und jedes von ihnen sonst seine eigene Spalte gebraucht haette. Jede dieser
 * Spalten waere die, die beim naechsten Produkt fehlt.
 *
 * Der Manager behaelt seine Ablage: Eine Art, die `categories()`
 * ueberschreibt, liest weiter ihr eigenes Feld, und der Rueckfall auf diese
 * Tabelle greift dort nie. Genau dafuer ist das Ueberschreiben da — ohne es
 * waeren die 206 Zuordnungen nach einem Update unsichtbar geworden. Nicht
 * verloren, aber unsichtbar, und das ist kaum besser.
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
