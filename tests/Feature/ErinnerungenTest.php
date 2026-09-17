<?php

use Illuminate\Support\Facades\Schema;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Models\TaskReminder;
use Peppermint\Tasks\Tests\Support\Offen;

/**
 * Erinnerungen gehoeren zur Aufgabe, nicht zum Produkt.
 *
 * Gebaut im Peppermint Manager (12/2025) und dort nie benutzt — Tabelle,
 * Oberflaeche und MCP-Werkzeug vorhanden, null Zeilen. Als AI Brain am
 * 17.09.2026 dasselbe brauchte, standen zwei Fassungen zur Wahl oder eine
 * gemeinsame. Und CRM und Verwaltung haben ebenfalls Aufgaben — es waere die
 * dritte und vierte Fassung geworden.
 */
beforeEach(function () {
    config()->set('tasks.statuses', [Offen::class]);
});

function aufgabe(string $titel = 'Etwas tun'): Task
{
    return Task::create(['title' => $titel, 'status' => 'offen']);
}

it('haengt mehrere Erinnerungen an dieselbe Aufgabe', function () {
    // Der Unterschied zum Faelligkeitsdatum: Davon gibt es genau EINES. Wer
    // „am Freitag ansehen, und nochmal vor dem Termin" braucht, kann das mit
    // einem Datum nicht ausdruecken — und verschiebt sonst die Frist, um
    // frueher erinnert zu werden. Das waere eine Luege ueber den Termin.
    $task = aufgabe();

    $task->reminders()->create(['user_id' => 1, 'remind_at' => now()->addDay(), 'title' => 'Freitag ansehen']);
    $task->reminders()->create(['user_id' => 1, 'remind_at' => now()->addDays(3), 'title' => 'Vor dem Termin']);

    expect($task->reminders()->count())->toBe(2);
});

it('trennt faellig von noch nicht faellig', function () {
    $task = aufgabe();
    $task->reminders()->create(['user_id' => 1, 'remind_at' => now()->subMinute()]);
    $task->reminders()->create(['user_id' => 1, 'remind_at' => now()->addHour()]);

    expect(TaskReminder::query()->due()->count())->toBe(1)
        ->and(TaskReminder::query()->upcoming()->count())->toBe(1);
});

it('behaelt abgehakte Erinnerungen als Verlauf', function () {
    // `is_dismissed` markiert, es loescht nicht. Die Zeile IST der Nachweis,
    // dass jemand erinnert wurde und gehandelt hat. Geloescht waere „ist noch
    // etwas offen" schneller zu beantworten und „woran haben wir schon
    // erinnert" gar nicht mehr.
    $task = aufgabe();
    $e = $task->reminders()->create(['user_id' => 1, 'remind_at' => now()->subHour()]);

    $e->dismiss();

    expect(TaskReminder::query()->count())->toBe(1)
        ->and($task->activeReminders()->count())->toBe(0)
        ->and($e->fresh()->dismissed_at)->not->toBeNull();
});

it('verschiebt den Zeitpunkt beim zweiten Abhaken nicht', function () {
    // Sonst sagt der Verlauf, die Person haette spaeter gehandelt als sie es
    // tat — und genau dafuer wird er aufgehoben.
    $task = aufgabe();
    $e = $task->reminders()->create(['user_id' => 1, 'remind_at' => now()->subHour()]);

    $e->dismiss();
    $zuerst = $e->fresh()->dismissed_at;

    $this->travel(5)->minutes();
    expect($e->fresh()->dismiss())->toBeFalse()
        ->and($e->fresh()->dismissed_at->equalTo($zuerst))->toBeTrue();
});

it('faellt mit der Aufgabe weg', function () {
    $task = aufgabe();
    $task->reminders()->create(['user_id' => 1, 'remind_at' => now()]);

    $task->delete();

    expect(TaskReminder::query()->count())->toBe(0);
});

it('haengt am Tabellennamen des Produkts, nicht an „tasks"', function () {
    // Der Fall, der sonst ein ganzes Produkt ausschliesst: In der Peppermint
    // Verwaltung SIND die Tickets die Aufgaben — eine `tasks`-Tabelle gibt es
    // dort gar nicht. Ein fest verdrahteter Fremdschluessel haette die
    // Migration dort unmoeglich gemacht.
    Schema::create('tickets', function ($t) {
        $t->id();
        $t->string('title');
        $t->string('status');
        $t->timestamps();
    });
    config()->set('tasks.tables.tasks', 'tickets');
    config()->set('tasks.tables.task_reminders', 'ticket_reminders');

    // Die Migrationsdatei DIREKT ausfuehren, nicht ueber `artisan migrate`:
    // Die Migration ist in diesem Lauf bereits verzeichnet und wuerde
    // uebersprungen — der Test haette dann nichts geprueft.
    Schema::dropIfExists('ticket_reminders');
    (require __DIR__.'/../../database/migrations/2026_09_17_000000_create_task_reminders_table.php')->up();

    expect(Schema::hasTable('ticket_reminders'))->toBeTrue();

    $ticket = Task::create(['title' => 'Drucker klemmt', 'status' => 'offen']);
    $ticket->reminders()->create(['user_id' => 7, 'remind_at' => now()]);

    expect((new TaskReminder)->getTable())->toBe('ticket_reminders')
        ->and($ticket->reminders()->count())->toBe(1);
});

it('legt die Tabelle nicht an, wenn das Produkt sie schon hat', function () {
    // Der Manager traegt seine seit 12/2025. Er uebernimmt das Paket DARUEBER,
    // statt migriert zu werden.
    expect(Schema::hasTable('task_reminders'))->toBeTrue();

    $vorher = Schema::getColumnListing('task_reminders');

    (require __DIR__.'/../../database/migrations/2026_09_17_000000_create_task_reminders_table.php')->up();

    expect(Schema::hasTable('task_reminders'))->toBeTrue()
        ->and(Schema::getColumnListing('task_reminders'))->toBe($vorher);
});
