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
        Schema::create(config('calendar.tables.categories', 'calendar_categories'), function (Blueprint $table) {
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

            $table->timestamps();

            // Eindeutig je Eigentuemer: Die gemeinsame „Sport" und die private
            // „Sport" einer Person duerfen nebeneinander stehen, zweimal
            // dieselbe fuer denselben Eigentuemer nicht.
            $table->unique(['owner_id', 'slug'], 'cal_categories_owner_slug_unique');
            $table->index(['is_active', 'sort_order'], 'cal_categories_active_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('calendar.tables.categories', 'calendar_categories'));
    }
};
