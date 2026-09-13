<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `meeting_url` verlaesst den Kern.
 *
 * iCalendar kennt kein solches Feld — ein Videocall-Link gehoert nach
 * CONFERENCE (RFC 7986), und das ist eine Eigenschaft der Terminart, nicht
 * jedes Termins. Ein Praesenz-Meeting braucht einen Ort, ein digitales einen
 * Link; solange beide Felder im Kern stehen, ist jedes von beiden „vielleicht"
 * und keins laesst sich erzwingen.
 *
 * Wer den Link braucht, legt ihn ins Profil seiner Art und liefert ihn beim
 * Export ueber einen benannten Haken.
 *
 * Bedingt ausgefuehrt: Anwendungen mit eigener Tabelle (`run_migrations` aus)
 * erreicht diese Migration ohnehin nicht, und eine frische Installation legt
 * die Spalte gar nicht erst an.
 *
 * Der Dateiname traegt bewusst ein echtes Datum statt der `0001_01_01`-Reihe
 * der Grundmigrationen: Eine Anwendung, die ihre Werte vorher woanders
 * hinretten will, muss ihre eigene Migration davor einsortieren koennen. Mit
 * `0001_01_01` liefe dieses Loeschen vor jeder App-Migration — und die
 * Rettung waere strukturell unmoeglich.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('calendar.tables.events', 'calendar_events');

        if (! Schema::hasColumn($table, 'meeting_url')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('meeting_url');
        });
    }

    public function down(): void
    {
        $table = config('calendar.tables.events', 'calendar_events');

        if (Schema::hasColumn($table, 'meeting_url')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('meeting_url', 500)->nullable();
        });
    }
};
