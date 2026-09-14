<?php

use Illuminate\Support\Carbon;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Tests\Support\Dringend;
use Peppermint\Tasks\Tests\Support\Fertig;
use Peppermint\Tasks\Tests\Support\Geparkt;
use Peppermint\Tasks\Tests\Support\InArbeit;
use Peppermint\Tasks\Tests\Support\Normal;
use Peppermint\Tasks\Tests\Support\Offen;
use Peppermint\Tasks\Urgency\FactorRegistry;
use Peppermint\Tasks\Urgency\UrgencyFactor;

beforeEach(function () {
    config()->set('tasks.statuses', [Offen::class, InArbeit::class, Geparkt::class, Fertig::class]);
    config()->set('tasks.priorities', [Dringend::class, Normal::class]);
});

it('summiert die Faktoren und behaelt die Begruendung', function () {
    $task = Task::create(['title' => 'Rechnung', 'status' => 'in_arbeit', 'priority' => 'dringend']);

    $ergebnis = $task->urgencyBreakdown();

    // 8.0 Prioritaet + 4.0 Status. Das Alter ist 0 Tage und faellt raus.
    expect($ergebnis['total'])->toBe(12.0)
        ->and($ergebnis['factors'])->toHaveKeys(['priority', 'status'])
        ->and($ergebnis['factors']['priority']['label'])->toBe('Priorität (Dringend)');
});

it('laesst Faktoren ohne Beitrag aus der Begruendung weg', function () {
    // Eine Liste aus Nullen erklaert nichts — sie macht die Begruendung
    // unleserlich und verdeckt die zwei Zeilen, auf die es ankommt.
    $task = Task::create(['title' => 'Ohne alles', 'status' => 'offen']);

    expect($task->urgencyBreakdown()['factors'])->toBeEmpty();
});

it('gibt einer erledigten Aufgabe keine Dringlichkeit, was auch immer sonst gilt', function () {
    // Sonst steht eine laengst erledigte, ueberfaellige Aufgabe fuer immer
    // ganz oben.
    $task = Task::create([
        'title' => 'Laengst erledigt',
        'status' => 'fertig',
        'priority' => 'dringend',
        'due_date' => Carbon::now()->subDays(30),
    ]);

    expect($task->urgency())->toBe(0.0)
        ->and($task->urgencyBreakdown()['factors'])->toBeEmpty();
});

it('traegt negative Faktoren — Geparktes soll sinken, nicht nur nicht steigen', function () {
    $task = Task::create(['title' => 'Spaeter', 'status' => 'geparkt', 'priority' => 'normal']);

    // 3.9 Prioritaet − 3.0 Status
    expect($task->urgency())->toBe(0.9);
});

it('gibt einer faelligen Aufgabe das volle Gewicht und einer fernen keines', function () {
    $heute = Task::create(['title' => 'Heute', 'status' => 'offen', 'due_date' => Carbon::now()]);
    $ueberfaellig = Task::create(['title' => 'Gestern', 'status' => 'offen', 'due_date' => Carbon::now()->subDays(5)]);
    $fern = Task::create(['title' => 'Irgendwann', 'status' => 'offen', 'due_date' => Carbon::now()->addDays(40)]);

    expect($heute->urgency())->toBe(12.0)
        ->and($ueberfaellig->urgency())->toBe(12.0)
        ->and($fern->urgency())->toBe(0.0);
});

it('steigt linear, je naeher die Faelligkeit rueckt', function () {
    $sieben = Task::create(['title' => 'In sieben Tagen', 'status' => 'offen', 'due_date' => Carbon::now()->addDays(7)]);
    $drei = Task::create(['title' => 'In drei Tagen', 'status' => 'offen', 'due_date' => Carbon::now()->addDays(3)]);

    // 12.0 * (1 - 7/14) = 6.0 und 12.0 * (1 - 3/14) = 9.43 -> 9.4
    expect($sieben->urgency())->toBe(6.0)
        ->and($drei->urgency())->toBe(9.4);
});

it('deckelt das Alter, damit Vergessenes keine Deadline ueberholt', function () {
    $task = Task::create(['title' => 'Uralt', 'status' => 'offen']);
    $task->forceFill(['created_at' => Carbon::now()->subDays(500)])->save();

    // 500 * 0.05 waeren 25.0 — gedeckelt auf 2.0.
    expect($task->fresh()->urgency())->toBe(2.0);
});

it('nimmt einen Faktor an, den die Anwendung selbst mitbringt', function () {
    // Der eigentliche Prüfstein: Neun der vierzehn Manager-Faktoren greifen auf
    // anwendungseigene Beziehungen zu. Traegt das Register sie, muss das Paket
    // weder Kommentare noch Blocker noch Zeitbloecke kennen.
    $eigener = new class extends UrgencyFactor
    {
        public function key(): string
        {
            return 'geplant';
        }

        public function label(Task $task): string
        {
            return 'Geplant';
        }

        public function score(Task $task): float
        {
            return 5.0;
        }
    };

    app(FactorRegistry::class)->register($eigener);

    $task = Task::create(['title' => 'Verplant', 'status' => 'offen']);

    expect($task->urgency())->toBe(5.0)
        ->and($task->urgencyBreakdown()['factors'])->toHaveKey('geplant');
});

it('laesst die Anwendung einen mitgelieferten Faktor abwaehlen', function () {
    app(FactorRegistry::class)->forget('priority');

    $task = Task::create(['title' => 'Ohne Prio-Gewicht', 'status' => 'offen', 'priority' => 'dringend']);

    expect($task->urgency())->toBe(0.0);
});

it('beschraenkt die Faktoren, wenn die Aufgabe das verlangt', function () {
    // Routinen sollen nicht im selben Rennen laufen wie echte Arbeit: Sie
    // kommen ohnehin wieder. Mit Alter und Prioritaet gewichtet stuenden sie
    // dauerhaft oben und verdraengten, was einmal zu tun ist.
    $beschraenkt = new class extends Task
    {
        protected $table = 'tasks';

        public function restrictUrgencyTo(): ?array
        {
            return ['status'];
        }
    };

    $beschraenkt->fill(['title' => 'Routine', 'status' => 'in_arbeit', 'priority' => 'dringend'])->save();

    // Ohne Beschraenkung waeren es 12.0 (8.0 Prioritaet + 4.0 Status).
    expect($beschraenkt->urgency())->toBe(4.0)
        ->and($beschraenkt->urgencyBreakdown()['factors'])->toHaveKeys(['status'])
        ->and($beschraenkt->urgencyBreakdown()['factors'])->not->toHaveKey('priority');
});
