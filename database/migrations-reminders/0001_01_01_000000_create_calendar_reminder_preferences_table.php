<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Vorlauf je Person — „keine / 15 / 30 Minuten vorher".
 *
 * Liegt bewusst NICHT bei den Migrationen, die das Paket von selbst laedt —
 * dieselbe Begruendung wie bei der Kategorienliste, nur eine Ebene tiefer:
 * Termin-Erinnerungen sind in der Vorgabe AUS. Ein Produkt, das sie nie
 * einschaltet, soll keine leere Tabelle bekommen.
 *
 *     php artisan vendor:publish --tag=calendar-reminder-leads
 *
 * ## Warum eine eigene Tabelle und keine Spalte an `users`
 *
 * Weil `users` jedem Produkt gehoert und keine zwei gleich heissen. Eine
 * Spalte dort muesste jedes Produkt selbst anlegen und das Paket ueber die
 * Spaltenabbildung finden — fuer einen Wert, der nur dieses Paket interessiert.
 * Eine eigene Tabelle kostet einen Join, den ohnehin niemand macht: Der
 * Abgleich liest alle Zeilen auf einmal.
 *
 * ## Warum `lead_minutes` nullable ist, und was das NICHT heisst
 *
 * Es heisst „keine Erinnerung" — eine Entscheidung, nicht ihre Abwesenheit.
 * Wer gar nichts entschieden hat, hat KEINE ZEILE; dann gilt die Vorgabe des
 * Produkts, und zwar weiter, auch wenn sie sich aendert. Zuruecksetzen loescht
 * deshalb die Zeile, statt den Vorgabewert hineinzuschreiben.
 *
 * Das ist dieselbe Regel wie bei den Benachrichtigungs-Einstellungen
 * (`peppermint/notifications`), nur mit einem Wert statt dreier Stufen — dort
 * heisst eine leere SPALTE „nicht entschieden", hier eine fehlende ZEILE. Der
 * Unterschied ist noetig, weil „aus" hier ein Wert sein muss und kein Fehlen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = (string) config('calendar.tables.reminder_preferences', 'calendar_reminder_preferences');

        // Wer sie schon hat, bekommt sie nicht zweimal. Ein Produkt kann die
        // Datei veroeffentlicht UND `calendar.run_migrations` an haben — dann
        // liegt dieselbe Migration unter zwei Namen vor, und Laravel fuehrt
        // beide aus.
        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $table) {
            $table->id();

            // Kein Fremdschluessel: Die Nutzertabelle heisst je Produkt anders,
            // und das Paket soll ihren Namen nicht brauchen. Dieselbe
            // Entscheidung wie bei `reminders.user_id`.
            $table->unsignedBigInteger('user_id')->unique();

            // NULL heisst „keine Erinnerung". Siehe Kopfkommentar.
            $table->unsignedSmallInteger('lead_minutes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tabelle = (string) config('calendar.tables.reminder_preferences', 'calendar_reminder_preferences');

        Schema::dropIfExists($tabelle);
    }
};
