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
