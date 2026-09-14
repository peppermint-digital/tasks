<?php

use Illuminate\Support\Facades\Schema;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Tests\Support\Fertig;
use Peppermint\Tasks\Tests\Support\GecastetesStatusEnum;
use Peppermint\Tasks\Tests\Support\Offen;

/**
 * Adoption ist Pflichtfeature, kein Zusatz.
 *
 * Ein Paket, das nur auf frischen Tabellen laeuft, kann ein gewachsenes
 * Produkt nicht nehmen — und dann wird es zweimal gebaut. Der Peppermint
 * Manager hat seine `tasks` seit Jahren mit eigenen Spaltennamen; muesste er
 * sie umbenennen, um das Paket zu benutzen, wuerde er es nicht benutzen.
 */
beforeEach(function () {
    config()->set('tasks.statuses', [Offen::class]);
});

it('liest ein Feld ueber den Spaltennamen, den das Produkt vergeben hat', function () {
    config()->set('tasks.columns.tasks', ['due_date' => 'faellig_am', 'assigned_to' => 'bearbeiter_id']);

    Schema::create('eigene_aufgaben', function ($t) {
        $t->id();
        $t->string('title');
        $t->string('status');
        $t->dateTime('faellig_am')->nullable();
        $t->unsignedBigInteger('bearbeiter_id')->nullable();
        $t->timestamps();
    });
    config()->set('tasks.tables.tasks', 'eigene_aufgaben');

    $task = Task::create(['title' => 'Fremde Spalten', 'status' => 'offen', 'faellig_am' => '2026-09-20 09:00:00']);

    expect(Task::column('due_date'))->toBe('faellig_am')
        ->and($task->field('due_date'))->not->toBeNull()
        ->and($task->getTable())->toBe('eigene_aufgaben');
});

it('faellt auf den Paketnamen zurueck, wo das Produkt nichts abbildet', function () {
    config()->set('tasks.columns.tasks', ['due_date' => 'faellig_am']);

    expect(Task::column('due_date'))->toBe('faellig_am')
        ->and(Task::column('title'))->toBe('title');
});

it('liest den Status auch, wenn das Produkt die Spalte auf ein Enum castet', function () {
    // Der Fall, an dem die erste echte Anbindung aufgelaufen ist: Der
    // Peppermint Manager castet `status` auf ein Enum, und das Paket bekam
    // dann ein Objekt statt einer Zeichenkette. Ein Produkt, das seine Spalten
    // castet, ist der Normalfall — nicht die Ausnahme, auf die man verzichten
    // kann.
    config()->set('tasks.statuses', [Offen::class, Fertig::class]);

    $model = new class extends Task
    {
        protected $table = 'tasks';

        protected $casts = ['status' => GecastetesStatusEnum::class];
    };

    $model->fill(['title' => 'Mit Enum', 'status' => 'fertig'])->save();

    expect($model->field('status'))->toBeInstanceOf(GecastetesStatusEnum::class)
        ->and($model->statusDefinition()?->key())->toBe('fertig')
        ->and($model->isTerminal())->toBeTrue();
});
