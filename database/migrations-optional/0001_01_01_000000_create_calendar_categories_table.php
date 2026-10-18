<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Kategorienliste.
 *
 * Liegt bewusst NICHT bei den Migrationen, die das Paket von selbst laedt:
 * Kategorien sind eine Zusatzfunktion. Wer keine fuehrt, soll auch keine leere
 * Tabelle bekommen. Wer welche will, veroeffentlicht diese Migration:
 *
 *     php artisan vendor:publish --tag=calendar-categories
 *
 * Ein Schalter in der Konfiguration waere der falsche Weg gewesen: Laravel
 * merkt sich eine Migration als ausgefuehrt, auch wenn sie nichts getan hat —
 * wer den Schalter spaeter umlegt, bekaeme die Tabelle nie.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = config('calendar.tables.categories', 'calendar_categories');

        // Wer seine Liste schon fuehrt, bekommt sie nicht zweimal.
        //
        // Gemessen am 18.10.2026: Der Manager fuehrt `event_categories` seit
        // Dezember 2025, das AI Brain `calendar_categories` mit sieben
        // Eintraegen. Beide haben die Tabelle laengst — ohne diese Frage
        // scheitert das Veroeffentlichen dort an einer Tabelle, die es
        // bereits gibt, und die Zusatzfunktion ist nur fuer frische Systeme
        // benutzbar.
        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $table) {
            $table->id();

            // Der Vergleichsschluessel. „Sport", „sport" und „Sport " sind
            // dieselbe Kategorie — ohne diese Spalte werden es drei.
            $table->string('slug', 100);

            $table->string('label');
            $table->string('colour', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // Leer = gehoert allen (verwaltete Liste). Gesetzt = gehoert einer
            // Person. Das ist der Unterschied zwischen einer gepflegten
            // Firmenliste und dem Wust, und er kostet genau eine Spalte.
            $table->unsignedBigInteger('owner_id')->nullable();

            // Fuer welche Terminart diese Kategorie angeboten wird.
            //
            // Leer = fuer jede. Das ist der Rueckfall und bleibt es: Wer die
            // Spalte nicht benutzt, merkt nichts von ihr.
            //
            // Gesetzt grenzt ein, und erst damit laesst sich beides in EINER
            // Liste halten: Im AI Brain tragen die privaten Kategorien
            // (Sport, Reise) die Art `private`, die Arbeitskategorien die
            // Arbeitsarten. Ohne diese Spalte stuenden beim Anlegen eines
            // Kundentermins auch „Sport" und „Reise" in der Auswahl — und die
            // Alternative waere eine zweite Liste gewesen, also zwei Orte, an
            // denen dasselbe gepflegt wird.
            $table->string('kind', 100)->nullable();

            $table->timestamps();

            // Eindeutig je Eigentuemer: Die gemeinsame „Sport" und die private
            // „Sport" einer Person duerfen nebeneinander stehen, zweimal
            // dieselbe fuer denselben Eigentuemer nicht.
            $table->unique(['owner_id', 'slug'], 'cal_categories_owner_slug_unique');
            $table->index(['kind', 'is_active', 'sort_order'], 'cal_categories_kind_active_order_idx');
            $table->index(['is_active', 'sort_order'], 'cal_categories_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('calendar.tables.categories', 'calendar_categories'));
    }
};
