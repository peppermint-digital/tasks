<?php

use Peppermint\Tasks\Exceptions\ForbiddenAttributeForKind;
use Peppermint\Tasks\Exceptions\UnknownTaskKind;
use Peppermint\Tasks\Kinds\KindRegistry;
use Peppermint\Tasks\Models\Task;
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
    config()->set('tasks.kinds', [Vorgang::class, Routine::class, NurAutomatisch::class]);
});

it('kennt die angemeldeten Arten und nur die', function () {
    $register = app(KindRegistry::class);

    expect($register->keys())->toBe(['vorgang', 'routine', 'ticket'])
        ->and($register->get('routine')->label())->toBe('Routine');

    // Die bekannten Schluessel gehoeren in die Meldung: Sonst weiss der Leser,
    // dass etwas fehlt, aber nicht ob er sich vertippt oder das Anmelden
    // vergessen hat.
    expect(fn () => $register->get('gibtsnicht'))
        ->toThrow(UnknownTaskKind::class, 'Registered: vorgang, routine, ticket');
});

it('bietet nur an, was ein Mensch auch anlegen kann', function () {
    // Ein Ticket entsteht, weil jemand an den Support geschrieben hat. In einer
    // Maske „was moechten Sie anlegen?" waere es eine Frage ohne Antwort — und
    // der so entstandene Eintrag haette keine Nachricht, fuer die es ihn gibt.
    expect(array_keys(app(KindRegistry::class)->creatable()))->toBe(['vorgang', 'routine']);
});

it('weist ein Feld ab, das die Art verbietet', function () {
    // Der Kern der Sache. Eine Routine mit Fälligkeitsdatum ist der Fall, der
    // dieser Methode ihre Form gegeben hat: Der Manager erlaubt eins, und es
    // tut nichts, weil die Rechnung es ignoriert.
    expect(fn () => Task::create([
        'title' => 'E-Mails sortieren',
        'status' => 'offen',
        'kind' => 'routine',
        'due_date' => '2026-10-01',
    ]))->toThrow(ForbiddenAttributeForKind::class, 'must not carry [due_date]');
});

it('laesst dieselbe Angabe an einer Art zu, die sie nicht verbietet', function () {
    // Gegenprobe: Der Riegel haengt an der ART, nicht am Feld. Ohne sie
    // bewiese der Test oben nur, dass irgendwas due_date abweist.
    $task = Task::create([
        'title' => 'Angebot schreiben',
        'status' => 'offen',
        'kind' => 'vorgang',
        'due_date' => '2026-10-01',
    ]);

    expect($task->exists)->toBeTrue();
});

it('laesst das verbotene Feld leeren', function () {
    // Eine Spalte, die schlicht fehlt oder ausdruecklich geleert wird, ist kein
    // Verstoss — das Feld zu raeumen, das die Art verbietet, ist der richtige
    // Handgriff.
    $task = Task::create(['title' => 'Kaffee kochen', 'status' => 'offen', 'kind' => 'routine', 'due_date' => null]);

    expect($task->exists)->toBeTrue();
});

it('weist einen Status ab, den die Art nicht kennt', function () {
    // Der Manager sagte „Routine-Aufgaben koennen nicht abgeschlossen werden"
    // in ZWEI Controllern — und ein dritter Aufrufer, das MCP-Werkzeug, hat es
    // nie erfahren. Hier gilt es fuer jeden, der schreibt.
    expect(fn () => Task::create([
        'title' => 'E-Mails sortieren',
        'status' => 'fertig',
        'kind' => 'routine',
    ]))->toThrow(ForbiddenAttributeForKind::class, 'status=fertig');
});

it('nimmt die Dringlichkeitsbeschraenkung von der Art', function () {
    // „Eine Routine laeuft nicht im selben Rennen wie echte Arbeit" ist eine
    // Aussage ueber Routinen, nicht ueber diese eine Zeile.
    $routine = Task::create(['title' => 'Aufraeumen', 'status' => 'offen', 'kind' => 'routine', 'priority' => 'dringend']);
    $vorgang = Task::create(['title' => 'Angebot', 'status' => 'offen', 'kind' => 'vorgang', 'priority' => 'dringend']);

    expect($routine->restrictUrgencyTo())->toBe(['priority'])
        ->and($vorgang->restrictUrgencyTo())->toBeNull();
});

it('bleibt lesbar, wenn eine Zeile eine unbekannte Art traegt', function () {
    // Ein Produkt, das eine Art umbenennt und eine Zeile uebersieht, soll eine
    // Aufgabe ohne Art sehen — keine Seite, die sich nicht oeffnen laesst.
    $task = Task::create(['title' => 'Altlast', 'status' => 'offen']);
    $task->forceFill(['kind' => 'abgeschafft'])->saveQuietly();

    expect($task->fresh()->kindDefinition())->toBeNull();
});

it('kommt ohne Arten aus', function () {
    // Ein Produkt mit einer Sorte Arbeit braucht keine. Eine Pflicht-Art waere
    // genau die Beschriftung, die wir vermeiden wollen.
    config()->set('tasks.kinds', []);

    $task = Task::create(['title' => 'Irgendwas', 'status' => 'offen', 'due_date' => '2026-10-01']);

    expect($task->exists)->toBeTrue()
        ->and($task->kindDefinition())->toBeNull();
});

it('leitet die Pflichtfelder aus den Regeln ab', function () {
    // Zweimal geschrieben waeren es zwei Orte zum Vergessen, und sie laufen
    // leise auseinander.
    expect((new Routine)->requiredAttributes())->toBe(['assigned_to']);
});
