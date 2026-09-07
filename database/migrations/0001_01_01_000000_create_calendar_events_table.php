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

            // Globally unique — carries iCalendar/CalDAV identity and survives a move.
            $table->uuid('uid')->unique();

            // Event kind. Deliberately without a default: omitting it raises an error
            // instead of silently picking the first one. Which values are valid is up
            // to the application's registration — the package knows none.
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

            // Visibility is NOT the kind: a business event may be confidential, a
            // private one openly shared. Putting both in one column leaves you guessing
            // which was meant — hence two columns.
            $table->string('visibility', 16)->default('shared');

            $table->uuid('recurrence_group_id')->nullable();
            $table->boolean('is_recurrence_master')->default(false);
            $table->json('recurrence_rules')->nullable();
            $table->date('recurrence_until')->nullable();

            // Only ever set for kinds that keep a trash bin. Kinds without one delete
            // for good — see EventKind::usesTrash().
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
