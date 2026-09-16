<?php

namespace Peppermint\Calendar;

use Illuminate\Support\ServiceProvider;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Console\PurgeTrashedEventsCommand;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Sources\EventSource;
use Peppermint\Calendar\Sources\EventSourceRegistry;

class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/calendar.php', 'calendar');

        $this->app->singleton(CategoryRegistry::class);

        $this->app->singleton(EventKindRegistry::class, function ($app): EventKindRegistry {
            $registry = new EventKindRegistry;

            foreach ((array) $app['config']->get('calendar.kinds', []) as $class) {
                $kind = $app->make($class);

                if (! $kind instanceof EventKind) {
                    throw new \InvalidArgumentException(
                        "Registered event kind [{$class}] does not extend ".EventKind::class.'.'
                    );
                }

                $registry->register($kind);
            }

            return $registry;
        });

        $this->app->singleton(EventSourceRegistry::class, function ($app): EventSourceRegistry {
            $registry = new EventSourceRegistry;

            foreach ((array) $app['config']->get('calendar.sources', []) as $class) {
                $source = $app->make($class);

                if (! $source instanceof EventSource) {
                    throw new \InvalidArgumentException(
                        "Registered event source [{$class}] does not extend ".EventSource::class.'.'
                    );
                }

                $registry->register($source);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        // An application that already has a calendar cannot use these as-is: its
        // tables exist, with columns of its own. It switches them off and brings
        // the schema across in its own migration instead — otherwise the first
        // migrate run collides with the tables it is supposed to adopt.
        if (config('calendar.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'calendar');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/calendar.php' => config_path('calendar.php'),
            ], 'calendar-config');

            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/calendar'),
            ], 'calendar-lang');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'calendar-migrations');

            // Kategorien sind eine Zusatzfunktion und werden deshalb nicht von
            // selbst geladen: Wer keine fuehrt, soll keine leere Tabelle
            // bekommen. Ein Schalter waere hier falsch — Laravel merkt sich
            // eine Migration als ausgefuehrt, auch wenn sie nichts getan hat.
            //
            // publishesMigrations() statt publishes(): Nur das stempelt beim
            // Veroeffentlichen einen aktuellen Zeitstempel auf den Dateinamen.
            // Mit publishes() landet die Datei unter ihrem Paketnamen in der
            // Anwendung — und der beginnt mit 0001_01_01, liefe also vor jeder
            // App-Migration. In einem Paket faellt das nicht auf, in der
            // einbindenden Anwendung schon.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations-optional' => database_path('migrations'),
            ], 'calendar-categories');

            $this->commands([
                PurgeTrashedEventsCommand::class,
            ]);
        }
    }
}
