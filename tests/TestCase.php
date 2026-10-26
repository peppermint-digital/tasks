<?php

namespace Peppermint\Tasks\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Peppermint\Reminders\RemindersServiceProvider;
use Peppermint\Tasks\TasksServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Das Erinnerungs-Paket mit: So steht es auch im Produkt, und nur so
        // laeuft seine Uebernahme-Migration, die `task_reminders` um den
        // Gegenstand ergaenzt. Ohne sie testet man eine Tabelle, die es in
        // keinem Produkt mehr gibt.
        return [RemindersServiceProvider::class, TasksServiceProvider::class];
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
        $this->loadMigrationsFrom(__DIR__.'/../vendor/peppermint/reminders/database/migrations');
    }
}
