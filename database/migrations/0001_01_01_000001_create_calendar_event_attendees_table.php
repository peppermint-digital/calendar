<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_attendees', function (Blueprint $table) {
            $table->id();

            // Cascade ist hier richtig: eine Teilnahme ohne Termin ist keine Angabe,
            // die jemand später noch braucht. Sie greift nur beim harten Löschen —
            // ein Termin im Papierkorb wird nicht aus der Datenbank entfernt.
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('token', 64)->nullable()->unique();

            $table->string('status', 16)->default('pending');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'user_id'], 'cal_attendees_event_user_idx');
            $table->unique(['event_id', 'email'], 'cal_attendees_event_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_attendees');
    }
};
