<?php

namespace Peppermint\Calendar;

use Illuminate\Support\ServiceProvider;
use Peppermint\Calendar\Categories\CategoryRegistry;
use Peppermint\Calendar\Console\PullCategoriesCommand;
use Peppermint\Calendar\Console\PurgeTrashedEventsCommand;
use Peppermint\Calendar\Console\ReconcileEventRemindersCommand;
use Peppermint\Calendar\Console\SeedWorkCategoriesCommand;
use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Kinds\EventKindRegistry;
use Peppermint\Calendar\Sources\EventSource;
use Peppermint\Calendar\Brain\KalenderFreigabe;
use Peppermint\Calendar\Sources\BrainPrivateEventSource;
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

        /*
        | Die Quelle fuer die privaten Termine aus der Nabe — gebunden, damit
        | die WACHE aus der Konfiguration kommt.
        |
        | Ohne diese Bindung wuerde der Behaelter die Klasse selbst bauen und
        | den Vorgabewert `null` fuer `$guard` nehmen. Das stimmt in Manager
        | und CRM und ist in der Verwaltung falsch — sie fuehrt ihre Menschen
        | auf einer anderen Wache. Genau dieser Unterschied war bei
        | `BrainCalendarSources` schon einmal der Grund fuer eine dritte Kopie.
        |
        | Ein Produkt traegt deshalb nur `BrainPrivateEventSource::class` in
        | `calendar.sources` ein und braucht keine Zeile Verdrahtung.
        */
        $this->app->singleton(KalenderFreigabe::class, fn ($app): KalenderFreigabe => new KalenderFreigabe(
            $app['config']->get('calendar.brain_private_events.guard'),
        ));

        $this->app->singleton(BrainPrivateEventSource::class, fn ($app): BrainPrivateEventSource => new BrainPrivateEventSource(
            $app->make(KalenderFreigabe::class),
        ));

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

            // Der Vorlauf je Person — aus demselben Grund veroeffentlicht und
            // nicht geladen, nur eine Ebene tiefer: Termin-Erinnerungen sind
            // in der Vorgabe AUS. Wer sie nie einschaltet, soll keine leere
            // Tabelle bekommen.
            //
            // Fehlt sie, faellt {@see ReminderLead} auf die Vorgabe des
            // Produkts zurueck und der Abgleich sagt es laut. Eine fehlende
            // Einstellungstabelle darf nicht dazu fuehren, dass niemand mehr
            // erinnert wird.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations-reminders' => database_path('migrations'),
            ], 'calendar-reminder-leads');

            $this->commands([
                ReconcileEventRemindersCommand::class,
                PurgeTrashedEventsCommand::class,
                PullCategoriesCommand::class,
                SeedWorkCategoriesCommand::class,
            ]);
        }
    }
}
