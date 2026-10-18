<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Spalte `kind` fuer Tabellen, die es schon gibt.
 *
 * Die Spalte steht auch in der create-Wanderung — fuer ein System, das die
 * Kategorien jetzt erst einschaltet. Hier geht es um die anderen: Der Manager
 * fuehrt seine `event_categories` seit Dezember 2025, das AI Brain seine
 * `calendar_categories` mit sieben Eintraegen. Deren Tabellen sind laengst
 * angelegt, und Laravel fuehrt eine ausgefuehrte Wanderung nicht erneut aus.
 *
 * ## Warum sie leer bleibt
 *
 * Leer heisst „fuer jede Terminart" — genau das, was ohne die Spalte galt.
 * Bestehende Zeilen behalten damit ihr Verhalten, ohne dass sie angefasst
 * werden. Das Eingrenzen ist ein zweiter, eigener Schritt und gehoert dem
 * System, das seine Arten kennt; eine Wanderung, die Daten umschreibt, waere
 * hier falsch: Sie wuerde raten, welche Kategorie zu welcher Art gehoert.
 *
 * ## Warum die Pruefungen
 *
 * `hasTable`, weil ein System ohne Kategorien diese Tabelle nicht hat und die
 * Wanderung dort nichts tun soll statt zu scheitern. `hasColumn`, weil ein
 * frisches System die Spalte schon aus der create-Wanderung mitbringt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = $this->tabelle();

        if (! Schema::hasTable($tabelle) || Schema::hasColumn($tabelle, 'kind')) {
            return;
        }

        Schema::table($tabelle, function (Blueprint $table) {
            $table->string('kind', 100)->nullable()->after('owner_id');
            $table->index(['kind', 'is_active', 'sort_order'], 'cal_categories_kind_active_order_idx');
        });
    }

    public function down(): void
    {
        $tabelle = $this->tabelle();

        if (! Schema::hasTable($tabelle) || ! Schema::hasColumn($tabelle, 'kind')) {
            return;
        }

        Schema::table($tabelle, function (Blueprint $table) {
            $table->dropIndex('cal_categories_kind_active_order_idx');
            $table->dropColumn('kind');
        });
    }

    private function tabelle(): string
    {
        return config('calendar.tables.categories', 'calendar_categories');
    }
};
