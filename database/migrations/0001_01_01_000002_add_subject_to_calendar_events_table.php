<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            // What this event is about, when it is about something the core does
            // not know: a task being time-blocked, a shift, a booking.
            //
            // A narrow reference on purpose. The alternative — a foreign key per
            // application concept — is how a shared table grows columns nobody
            // else uses. This one stays two columns no matter how many kinds of
            // subject exist, and it lets the core answer "is this task planned
            // yet?" without knowing what a task is.
            $table->string('subject_type', 191)->nullable()->after('owner_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');

            $table->index(['subject_type', 'subject_id'], 'cal_events_subject_idx');
            $table->index(['owner_id', 'subject_type', 'subject_id'], 'cal_events_owner_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex('cal_events_owner_subject_idx');
            $table->dropIndex('cal_events_subject_idx');
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
