<?php

namespace Peppermint\Tasks\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Peppermint\Tasks\TasksServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TasksServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite erzwingt Fremdschluessel standardmaessig NICHT. Ohne das
            // laeuft jede Pruefung auf `cascadeOnDelete` ins Leere und meldet
            // gruen — waehrend MySQL im Produkt tatsaechlich kaskadiert. Ein
            // Test, der den Unterschied nicht sehen kann, prueft hier nichts.
            'foreign_key_constraints' => true,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
