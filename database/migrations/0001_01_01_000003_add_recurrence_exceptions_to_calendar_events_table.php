<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Calendar\Models\CalendarEvent;

/**
 * Ausgenommene Vorkommen einer Serie.
 *
 * Eine Serie ist eine Zeile mit einer Regel. Wer ein einzelnes Vorkommen
 * absagt oder verschiebt, will damit nicht die Regel ändern — die übrigen
 * Termine bleiben, wie sie waren. Die abgesagten Tage stehen deshalb als Liste
 * am Termin (das EXDATE der iCalendar-Welt), und ein verschobenes Vorkommen ist
 * eine eigene Zeile derselben `recurrence_group_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new CalendarEvent)->getTable(), function (Blueprint $table) {
            $table->json('recurrence_exceptions')->nullable()->after('recurrence_until');
        });
    }

    public function down(): void
    {
        Schema::table((new CalendarEvent)->getTable(), function (Blueprint $table) {
            $table->dropColumn('recurrence_exceptions');
        });
    }
};
