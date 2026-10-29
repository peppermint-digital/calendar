<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnierte fremde Kalender und ihre Termine.
 *
 * Liegt bewusst NICHT bei den Migrationen, die das Paket von selbst laedt —
 * dieselbe Begruendung wie bei der Kategorienliste und beim Vorlauf je Person:
 * Abos sind eine Zusatzfunktion. Ein Produkt, das keine fuehrt, soll keine
 * leeren Tabellen bekommen.
 *
 *     php artisan vendor:publish --tag=calendar-subscriptions
 *
 * ## Die Namen sind die aus dem Bestand, und zwar absichtlich
 *
 * `external_calendars` und `external_calendar_events` heissen im Peppermint
 * Manager seit 12/2025 so. Am 29.10.2026 ziehen die Abos nach AI Brain
 * (Entscheidung: „Das sollte gar nicht mehr im Manager verfuegbar sein") — und
 * dort bekommen sie KEIN `brain_`-Praefix. Ein Praefix wuerde sie als „die vom
 * Brain" markieren, obwohl sie danach die einzigen sind.
 *
 * ## Kein Fremdschluessel auf die Nutzertabelle
 *
 * Die heisst je Produkt anders, und das Paket soll ihren Namen nicht brauchen —
 * dieselbe Entscheidung wie bei `reminders.user_id` und beim Vorlauf je Person.
 * Das Aufraeumen beim Loeschen eines Menschen gehoert damit dem Produkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $abos = $this->abos();
        $termine = $this->termine();

        if (! Schema::hasTable($abos)) {
            Schema::create($abos, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->text('url');
                $table->string('color', 7)->default('#6b7280');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'is_active']);
            });
        }

        if (! Schema::hasTable($termine)) {
            Schema::create($termine, function (Blueprint $table) use ($abos) {
                $table->id();
                $table->foreignId('external_calendar_id')->constrained($abos)->cascadeOnDelete();

                // Der Schluessel DIESER Zeile: Quell-Kennung plus Startdatum.
                // Ohne den Tag ueberschreiben sich die Vorkommen einer Serie
                // gegenseitig — sie tragen alle dieselbe UID.
                $table->string('uid');

                // Die rohe UID aus dem Feed. Nur damit laesst sich ein
                // Abo-Termin mit einem EIGENEN Termin desselben Hauses
                // zusammenbringen — die Doppelungsregel braucht sie.
                $table->string('source_uid')->nullable();

                $table->string('title');
                $table->text('description')->nullable();
                $table->string('location')->nullable();
                $table->dateTime('start_datetime');
                $table->dateTime('end_datetime');
                $table->boolean('all_day')->default(false);
                $table->timestamps();

                $table->index(['external_calendar_id', 'start_datetime'], 'cal_sub_events_cal_start_idx');
                $table->unique(['external_calendar_id', 'uid'], 'cal_sub_events_cal_uid_unique');
                $table->index('source_uid', 'cal_sub_events_source_uid_idx');

                // Fuer den Erinnerungs-Abgleich: „alle kommenden Termine
                // aktiver Abos". Ohne diesen Index ist das ein voller
                // Durchlauf, und zwar alle fuenf Minuten.
                $table->index('start_datetime', 'cal_sub_events_start_idx');
            });
        }
    }

    public function down(): void
    {
        // Reihenfolge: zuerst die Termine, dann die Abos — der Fremdschluessel
        // zeigt in diese Richtung.
        Schema::dropIfExists($this->termine());
        Schema::dropIfExists($this->abos());
    }

    private function abos(): string
    {
        return (string) config('calendar.tables.subscriptions', 'external_calendars');
    }

    private function termine(): string
    {
        return (string) config('calendar.tables.subscribed_events', 'external_calendar_events');
    }
};
