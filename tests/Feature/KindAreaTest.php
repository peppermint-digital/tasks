<?php

use Peppermint\Tasks\Kinds\KindRegistry;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Tests\Support\Anfrage;
use Peppermint\Tasks\Tests\Support\Dringend;
use Peppermint\Tasks\Tests\Support\Fertig;
use Peppermint\Tasks\Tests\Support\InArbeit;
use Peppermint\Tasks\Tests\Support\Normal;
use Peppermint\Tasks\Tests\Support\NurAutomatisch;
use Peppermint\Tasks\Tests\Support\Offen;
use Peppermint\Tasks\Tests\Support\Routine;
use Peppermint\Tasks\Tests\Support\Vorgang;

beforeEach(function () {
    config()->set('tasks.statuses', [Offen::class, InArbeit::class, Fertig::class]);
    config()->set('tasks.priorities', [Dringend::class, Normal::class]);
    config()->set('tasks.kinds', [Vorgang::class, Routine::class, NurAutomatisch::class, Anfrage::class]);
});

it('gibt einer Art ohne Angabe keinen Bereich', function () {
    // Die Vorgabe ist null, damit jede Art in Manager, CRM und Verwaltung
    // unveraendert bleibt.
    expect((new Vorgang)->area())->toBeNull()
        ->and(app(KindRegistry::class)->get('ticket')->area())->toBeNull();
});

it('kennt den Bereich einer Art, die einen nennt', function () {
    expect(app(KindRegistry::class)->get('anfrage')->area())->toBe('crm');
});

it('liefert die Arten eines Bereichs und nur die', function () {
    $register = app(KindRegistry::class);

    expect(array_keys($register->forArea('crm')))->toBe(['anfrage'])
        // Ein Bereich ohne Arten ist ein normaler Zustand und kein Fehler.
        ->and($register->forArea('lager'))->toBe([]);
});

it('laesst eine bestehende Art unveraendert', function () {
    // Gegenprobe: Der Bereich nimmt der Routine nichts weg, sie bleibt im
    // Register, anlegbar und mit ihren Riegeln, und gehoert keinem Bereich an.
    $register = app(KindRegistry::class);
    $routine = $register->get('routine');

    expect($routine->area())->toBeNull()
        ->and($routine->forbiddenAttributes())->toBe(['due_date'])
        ->and($routine->statusKeys())->toBe(['offen'])
        ->and(array_keys($register->creatable()))->toBe(['vorgang', 'routine', 'anfrage'])
        ->and($register->forArea(''))->toBe([]);
});

it('sagt einer Aufgabe, zu welchem Bereich sie gehoert', function () {
    $crm = Task::create(['title' => 'Rueckruf Mueller', 'status' => 'offen', 'kind' => 'anfrage']);
    $ohne = Task::create(['title' => 'Angebot schreiben', 'status' => 'offen', 'kind' => 'vorgang']);
    $unbekannt = Task::create(['title' => 'Altlast', 'status' => 'offen', 'kind' => 'verschwunden']);

    expect($crm->kindArea())->toBe('crm')
        ->and($ohne->kindArea())->toBeNull()
        ->and($unbekannt->kindArea())->toBeNull();
});

it('filtert Aufgaben nach Bereich ueber ihre Arten', function () {
    Task::create(['title' => 'Rueckruf Mueller', 'status' => 'offen', 'kind' => 'anfrage']);
    Task::create(['title' => 'Angebot schreiben', 'status' => 'offen', 'kind' => 'vorgang']);
    Task::create(['title' => 'Ohne Art', 'status' => 'offen']);

    expect(Task::inArea('crm')->pluck('title')->all())->toBe(['Rueckruf Mueller'])
        // Ein Bereich ohne Arten trifft nichts, nicht alles.
        ->and(Task::inArea('lager')->count())->toBe(0);
});
