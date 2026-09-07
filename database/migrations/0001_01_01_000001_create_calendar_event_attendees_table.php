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

            // A cascade is right here: attendance without its event is not information
            // anyone needs later. It only fires on a hard delete — an event in the trash
            // bin is never removed from the database.
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
