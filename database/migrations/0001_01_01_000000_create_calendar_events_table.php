<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();

            // Kalender-übergreifend eindeutig — trägt ICS/CalDAV und übersteht einen Umzug.
            $table->uuid('uid')->unique();

            // Terminart. Bewusst ohne Default: wer die Art nicht nennt, bekommt einen
            // Fehler statt stillschweigend die erste. Welche Werte gelten, entscheidet
            // die Anwendung über die Registrierung — das Paket kennt sie nicht.
            $table->string('kind', 64);

            $table->unsignedBigInteger('owner_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('meeting_url', 500)->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('timezone', 64)->nullable();

            // Sichtbarkeit ist NICHT die Art: ein geschäftlicher Termin kann vertraulich
            // sein, ein privater offen geteilt. Wer beides in eine Spalte legt, kann
            // später nur noch raten, was gemeint war — deshalb zwei Spalten.
            $table->string('visibility', 16)->default('shared');

            $table->uuid('recurrence_group_id')->nullable();
            $table->boolean('is_recurrence_master')->default(false);
            $table->json('recurrence_rules')->nullable();
            $table->date('recurrence_until')->nullable();

            // Wird nur gesetzt, wenn die Terminart einen Papierkorb führt. Arten ohne
            // Papierkorb löschen hart — siehe EventKind::usesTrash().
            $table->softDeletes();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at'], 'cal_events_range_idx');
            $table->index(['owner_id', 'starts_at'], 'cal_events_owner_start_idx');
            $table->index(['kind', 'starts_at'], 'cal_events_kind_start_idx');
            $table->index('recurrence_group_id', 'cal_events_rec_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
