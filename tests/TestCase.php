<?php

namespace Peppermint\Calendar\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Peppermint\Calendar\CalendarServiceProvider;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Tests\Fixtures\BusinessKind;
use Peppermint\Calendar\Tests\Fixtures\CategorisedKind;
use Peppermint\Calendar\Tests\Fixtures\PlainCategorisedKind;
use Peppermint\Calendar\Tests\Fixtures\PrivateKind;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Das Gemerkte aus `CategoryRegistry::assignmentsAvailable()` darf
        // nicht zwischen Tests durchschlagen: Ein Test, der die
        // Zuordnungstabelle entfernt, haette sonst den naechsten mit seiner
        // Antwort versorgt — und der waere dann aus dem falschen Grund gruen.
        CategoryRegistry::forgetAvailability();
    }

    protected function getPackageProviders($app): array
    {
        $anbieter = [CalendarServiceProvider::class];

        // Nur wenn es da ist — `peppermint/reminders` ist ein `suggest`, kein
        // `require`. Genauso entscheidet es sich im Produkt.
        if (class_exists(\Peppermint\Reminders\RemindersServiceProvider::class)) {
            $anbieter[] = \Peppermint\Reminders\RemindersServiceProvider::class;
        }

        return $anbieter;
    }

    protected function defineEnvironment($app): void
    {
        // Foreign keys enforced so tests see the same rules MySQL applies.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        $app['config']->set('calendar.kinds', [
            BusinessKind::class,
            PrivateKind::class,
            // Eine Art, deren Kategorien je Termin verschieden sind — fuer die
            // Auswertung. Hier und nicht per `config()->set` im Test: Das
            // Register entsteht beim Hochfahren, eine spaetere Aenderung an der
            // Konfiguration erreicht es nicht mehr.
            CategorisedKind::class,
            // Eine Art, die Kategorien nur zustimmt — fuer den Rueckfall
            // auf die Zuordnung, die das Paket selbst haelt.
            PlainCategorisedKind::class,
            // Eine Art ohne Kategorie, die aber eine Gruppe nennen kann —
            // die Lage eines Zeitblocks.
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (is_dir($erinnerungen = __DIR__.'/../vendor/peppermint/reminders/database/migrations')) {
            $this->loadMigrationsFrom($erinnerungen);
        }

        // Sonst nur per vendor:publish — in Tests direkt geladen, damit der
        // Vorlauf je Person ueberhaupt pruefbar ist. Dass er ohne die Tabelle
        // auf die Vorgabe zurueckfaellt, prueft ein eigener Test, der sie
        // wieder wegnimmt.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations-reminders');

        // Ebenfalls nur per vendor:publish — in Tests direkt geladen, damit die
        // abonnierten Kalender pruefbar sind.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations-subscriptions');

        // Sonst nur per vendor:publish — in Tests direkt geladen, damit die
        // Kategorienfunktion ueberhaupt pruefbar ist.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations-optional');

        // Profile tables live in the application, not the package — these two
        // stand in for applications with different extra fields.
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
