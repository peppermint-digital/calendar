<?php

namespace Peppermint\Calendar\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Peppermint\Calendar\CalendarServiceProvider;
use Peppermint\Calendar\Tests\Fixtures\BusinessKind;
use Peppermint\Calendar\Tests\Fixtures\PrivateKind;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CalendarServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Fremdschlüssel scharf, damit die Tests dieselben Regeln sehen wie MySQL.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        $app['config']->set('calendar.kinds', [
            BusinessKind::class,
            PrivateKind::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Profiltabellen liegen in der Anwendung, nicht im Paket — hier stehen
        // zwei Beispiele für Anwendungen mit unterschiedlichen Zusatzfeldern.
        Schema::create('calendar_event_profile_business', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->boolean('is_not_billable')->default(false);
        });

        Schema::create('calendar_event_profile_private', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->unsignedBigInteger('habit_id')->nullable();
        });
    }
}
