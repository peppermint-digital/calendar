<?php

namespace Peppermint\Calendar;

use Illuminate\Support\ServiceProvider;
use Peppermint\Calendar\Console\PurgeTrashedEventsCommand;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;

class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/calendar.php', 'calendar');

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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'calendar');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/calendar.php' => config_path('calendar.php'),
            ], 'calendar-config');

            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/calendar'),
            ], 'calendar-lang');

            $this->commands([
                PurgeTrashedEventsCommand::class,
            ]);
        }
    }
}
