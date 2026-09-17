<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Models\TaskReminder;
use Peppermint\Tasks\Reminders\DueDateReminders;
use Peppermint\Tasks\Tests\Support\Offen;

/**
 * Aus einer Frist wird eine Erinnerung, die jemanden erreicht.
 *
 * Eine Frist sortiert eine Liste und hebt eine Dringlichkeitszahl. Sie sagt
 * NIEMANDEM Bescheid. In AI Brain waren am 17.09.2026 fuenf Aufgaben mit
 * Frist offen, vier davon faellig oder ueberfaellig — und keiner wusste es.
 */
beforeEach(function () {
    config()->set('tasks.statuses', [Offen::class]);
    config()->set('tasks.due_reminders', [
        'enabled' => true,
        'lead_days' => 1,
        'hour' => 8,
        'timezone' => 'Europe/Berlin',
    ]);

    // Ein fester Moment. Ohne ihn haengt jede Aussage ueber „gestern" und
    // „morgen" an der Uhr des Testlaufs, und der Test wird um Mitternacht rot.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-17 09:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function mitFrist(string $frist, ?int $bearbeiter = 7): Task
{
    return Task::create([
        'title' => 'Etwas tun',
        'status' => 'offen',
        'due_date' => $frist,
        'assigned_to' => $bearbeiter,
    ]);
}

it('legt Vorlauf und Tag selbst an', function () {
    mitFrist('2026-09-25');

    $bilanz = (new DueDateReminders)->reconcile();

    expect($bilanz['created'])->toBe(2)
        ->and(TaskReminder::pluck('source')->sort()->values()->all())
        ->toBe(['due:day', 'due:lead']);
});

it('setzt die Zeitpunkte auf 08:00 ORTSZEIT, nicht UTC', function () {
    // Im September gilt Sommerzeit: 08:00 in Berlin sind 06:00 UTC. Wer die
    // Stunde ungerechnet in die UTC-Spalte schreibt, meldet um zehn.
    mitFrist('2026-09-25');

    (new DueDateReminders)->reconcile();

    expect(TaskReminder::where('source', 'due:day')->value('remind_at')->utc()->format('Y-m-d H:i'))
        ->toBe('2026-09-25 06:00')
        ->and(TaskReminder::where('source', 'due:lead')->value('remind_at')->utc()->format('Y-m-d H:i'))
        ->toBe('2026-09-24 06:00');
});

it('gibt einer laengst ueberfaelligen Aufgabe GENAU EINE Zeile', function () {
    // Beide Momente liegen in der Vergangenheit. Beide anzulegen hiesse zwei
    // Meldungen im selben Durchgang fuer dieselbe Sache — und danach sieht
    // man bei der Glocke weg.
    mitFrist('2026-04-15');

    $bilanz = (new DueDateReminders)->reconcile();

    expect($bilanz['created'])->toBe(1)
        ->and(TaskReminder::value('source'))->toBe('due:day');
});

it('legt beim zweiten Durchgang nichts doppelt an', function () {
    mitFrist('2026-09-25');

    (new DueDateReminders)->reconcile();
    $zweiter = (new DueDateReminders)->reconcile();

    expect($zweiter['created'])->toBe(0)
        ->and(TaskReminder::count())->toBe(2);
});

it('nimmt die Erinnerung mit, wenn die Frist verschoben wird', function () {
    $task = mitFrist('2026-09-25');
    (new DueDateReminders)->reconcile();

    $task->update(['due_date' => '2026-10-02']);
    $bilanz = (new DueDateReminders)->reconcile();

    expect($bilanz['moved'])->toBe(2)
        ->and($bilanz['moved_ids'])->toHaveCount(2)
        ->and(TaskReminder::where('source', 'due:day')->value('remind_at')->utc()->format('Y-m-d'))
        ->toBe('2026-10-02');
});

it('nimmt die Erinnerung mit, wenn die Aufgabe den Bearbeiter wechselt', function () {
    $task = mitFrist('2026-09-25', 7);
    (new DueDateReminders)->reconcile();

    $task->update(['assigned_to' => 9]);
    (new DueDateReminders)->reconcile();

    expect(TaskReminder::pluck('user_id')->unique()->all())->toBe([9]);
});

it('raeumt weg, was keine Frist mehr hat', function () {
    $task = mitFrist('2026-09-25');
    (new DueDateReminders)->reconcile();

    $task->update(['due_date' => null]);
    $bilanz = (new DueDateReminders)->reconcile();

    expect($bilanz['removed'])->toBe(2)
        ->and(TaskReminder::count())->toBe(0);
});

it('hoert auf zu mahnen, sobald die Aufgabe erledigt ist', function () {
    $task = mitFrist('2026-09-25');
    (new DueDateReminders)->reconcile();

    $task->update(['status' => 'erledigt']);
    (new DueDateReminders)->reconcile();

    expect(TaskReminder::count())->toBe(0);
});

it('laesst eine abgehakte Zeile stehen, auch wenn ihr Anlass entfaellt', function () {
    // Sie ist der Nachweis, dass jemand erinnert wurde und gehandelt hat.
    // Sie nachtraeglich zu entfernen hiesse, die Geschichte umzuschreiben.
    $task = mitFrist('2026-09-25');
    (new DueDateReminders)->reconcile();
    TaskReminder::query()->update(['is_dismissed' => true, 'dismissed_at' => now()]);

    $task->update(['due_date' => null]);
    (new DueDateReminders)->reconcile();

    expect(TaskReminder::count())->toBe(2);
});

it('fasst eine abgehakte Zeile auch beim Verschieben nicht an', function () {
    $task = mitFrist('2026-09-25');
    (new DueDateReminders)->reconcile();
    TaskReminder::where('source', 'due:day')->update(['is_dismissed' => true]);

    $task->update(['due_date' => '2026-10-02']);
    (new DueDateReminders)->reconcile();

    expect(TaskReminder::where('source', 'due:day')->value('remind_at')->utc()->format('Y-m-d'))
        ->toBe('2026-09-25');
});

it('zaehlt Aufgaben ohne Empfaenger, statt sie still zu uebergehen', function () {
    // Ein Lauf, der schweigend nichts tut, meldet Erfolg — und die Luecke
    // faellt erst Monate spaeter auf.
    mitFrist('2026-09-25', null);

    $bilanz = (new DueDateReminders)->reconcile();

    expect($bilanz['unaddressed'])->toBe(1)
        ->and($bilanz['created'])->toBe(0)
        ->and(TaskReminder::count())->toBe(0);
});

it('nimmt den Empfaenger, den das Produkt bestimmt', function () {
    // Das Paket kennt nur `assigned_to`. Ein Produkt mit einem Ersteller-Feld
    // reicht seinen eigenen Rueckfall herein — die Spalte gehoert ihm, nicht
    // dem Kern.
    mitFrist('2026-09-25', null);

    (new DueDateReminders(fn (Task $t) => 42))->reconcile();

    expect(TaskReminder::value('user_id'))->toBe(42);
});

it('laesst von Hand gesetzte Erinnerungen in Ruhe', function () {
    $task = mitFrist('2026-09-25');
    $eigene = $task->taskReminders()->create(['user_id' => 1, 'remind_at' => now()->addDay(), 'title' => 'Selbst gesetzt']);

    $task->update(['due_date' => null]);
    (new DueDateReminders)->reconcile();

    expect($eigene->fresh())->not->toBeNull()
        ->and($eigene->fresh()->title)->toBe('Selbst gesetzt');
});
