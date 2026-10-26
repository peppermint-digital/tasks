<?php

namespace Peppermint\Tasks;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Peppermint\Tasks\Kinds\KindRegistry;
use Peppermint\Tasks\Kinds\TaskKind;
use Peppermint\Tasks\Priority\PriorityRegistry;
use Peppermint\Tasks\Priority\TaskPriority;
use Peppermint\Tasks\Sources\TaskSource;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Status\StatusRegistry;
use Peppermint\Tasks\Status\TaskStatus;
use Peppermint\Tasks\Urgency\FactorRegistry;
use Peppermint\Tasks\Urgency\UrgencyEngine;
use Peppermint\Tasks\Urgency\UrgencyFactor;

class TasksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tasks.php', 'tasks');

        $this->verdrahteErinnerungsPaket();

        $this->app->singleton(StatusRegistry::class, fn ($app) => $this->build(
            new StatusRegistry,
            (array) $app['config']->get('tasks.statuses', []),
            TaskStatus::class,
            'status',
        ));

        $this->app->singleton(PriorityRegistry::class, fn ($app) => $this->build(
            new PriorityRegistry,
            (array) $app['config']->get('tasks.priorities', []),
            TaskPriority::class,
            'priority',
        ));

        $this->app->singleton(KindRegistry::class, fn ($app) => $this->build(
            new KindRegistry,
            (array) $app['config']->get('tasks.kinds', []),
            TaskKind::class,
            'task kind',
        ));

        $this->app->singleton(FactorRegistry::class, fn ($app) => $this->build(
            new FactorRegistry,
            (array) $app['config']->get('tasks.urgency_factors', []),
            UrgencyFactor::class,
            'urgency factor',
        ));

        $this->app->singleton(TaskSourceRegistry::class, fn ($app) => $this->build(
            new TaskSourceRegistry,
            (array) $app['config']->get('tasks.sources', []),
            TaskSource::class,
            'task source',
        ));

        $this->app->singleton(UrgencyEngine::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/tasks.php' => config_path('tasks.php')], 'tasks-config');

        // A product that already has its own table adopts the package instead
        // of being migrated on top of.
        if (config('tasks.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Tell the reminders package what this one already knows.
     *
     * Reminders moved to `peppermint/reminders` in 10/2026. Four products were
     * already running the table under a name they configured HERE, and one of
     * them does not call it `task_reminders` at all: the Peppermint Verwaltung
     * maps it to `ticket_reminders`, because there the tickets ARE the tasks.
     *
     * Without this, the reminders package would look for its own default name,
     * find nothing, and its create-migration would add a SECOND reminder table
     * next to the live one. Two stores is the bug the extraction was meant to
     * end, so producing one by installing a package would be a poor start.
     *
     * Only when the reminders config is still untouched. A product that set it
     * deliberately has said something, and a package that overrides a
     * deliberate setting is a package people stop configuring.
     */
    protected function verdrahteErinnerungsPaket(): void
    {
        $config = $this->app['config'];

        $vorgabeDesPakets = 'reminders';

        if ($config->get('reminders.tables.reminders', $vorgabeDesPakets) !== $vorgabeDesPakets) {
            return;
        }

        $eigene = $config->get('tasks.tables.task_reminders', 'task_reminders');

        $config->set('reminders.tables.reminders', $eigene);

        // Dieselbe Frage fuer die Spalten: wer sie hier umbenannt hat, hat es
        // getan, bevor es das Erinnerungs-Paket gab.
        if ($config->get('reminders.columns.reminders', []) === []) {
            $config->set('reminders.columns.reminders', $config->get('tasks.columns.task_reminders', []));
        }

        // Und was `task_id` bedeutete. Nur DIESES Paket weiss das — das
        // Erinnerungs-Paket darf nicht wissen, dass es Aufgaben gibt, und ein
        // Produkt sollte es nicht von Hand nachtragen muessen, nur weil wir
        // eine Tabelle umgebaut haben.
        //
        // `relax` bleibt ausdruecklich aus: Es ist der einzige Schritt, der
        // eine bestehende Bedingung aendert statt etwas hinzuzufuegen, und er
        // laeuft gegen lebende Zeilen. Wer ihn will, schaltet ihn im Produkt
        // ein — ein Deploy darf das nicht aus Versehen tun.
        if ($config->get('reminders.legacy_subject') === null) {
            $config->set('reminders.legacy_subject', [
                'column' => 'task_id',
                'type' => $config->get('tasks.task_model'),
                'relax' => false,
            ]);
        }
    }

    /**
     * Resolve the configured class names and hand them to the registry.
     *
     * The type check is worth its lines: a typo in `config/tasks.php` would
     * otherwise surface much later as a missing method on something nobody
     * expected to be there.
     *
     * @template T of StatusRegistry|PriorityRegistry|FactorRegistry|TaskSourceRegistry
     *
     * @param  T  $registry
     * @param  array<int, class-string>  $classes
     * @return T
     */
    protected function build(object $registry, array $classes, string $contract, string $was): object
    {
        foreach ($classes as $class) {
            $entry = $this->app->make($class);

            if (! $entry instanceof $contract) {
                throw new InvalidArgumentException(
                    "Registered {$was} [{$class}] does not extend {$contract}."
                );
            }

            $registry->register($entry);
        }

        return $registry;
    }
}
