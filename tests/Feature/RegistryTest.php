<?php

use Peppermint\Tasks\Exceptions\UnknownStatus;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Status\StatusRegistry;
use Peppermint\Tasks\Status\VtodoStatus;
use Peppermint\Tasks\Tests\Support\Fertig;
use Peppermint\Tasks\Tests\Support\Geparkt;
use Peppermint\Tasks\Tests\Support\InArbeit;
use Peppermint\Tasks\Tests\Support\Offen;

beforeEach(function () {
    config()->set('tasks.statuses', [Offen::class, InArbeit::class, Geparkt::class, Fertig::class]);
});

it('bildet ein eigenes Vokabular auf die vier VTODO-Werte ab', function () {
    // Der aeussere Massstab. Zwei Systeme duerfen „offen" und „todo" sagen —
    // was „fertig" heisst, darf keine Geschmacksfrage sein, denn daran haengen
    // Export, Dringlichkeit und jeder Vergleich ueber Systemgrenzen.
    $register = app(StatusRegistry::class);

    expect($register->get('offen')->vtodo())->toBe(VtodoStatus::NeedsAction)
        ->and($register->get('in_arbeit')->vtodo())->toBe(VtodoStatus::InProcess)
        ->and($register->get('fertig')->vtodo())->toBe(VtodoStatus::Completed);
});

it('leitet terminal aus VTODO ab, nicht aus einer zweiten Liste', function () {
    $register = app(StatusRegistry::class);

    expect($register->get('fertig')->isTerminal())->toBeTrue()
        ->and($register->get('geparkt')->isTerminal())->toBeFalse()
        ->and($register->terminalKeys())->toBe(['fertig']);
});

it('sagt bei einem unbekannten Status, was stattdessen angemeldet ist', function () {
    // Eine blosse Meldung „unbekannter Status" schickt den Leser auf Tippfehler-
    // suche; die Antwort ist fast immer eine fehlende Zeile in der Konfiguration.
    expect(fn () => app(StatusRegistry::class)->get('erfunden'))
        ->toThrow(UnknownStatus::class, 'offen, in_arbeit, geparkt, fertig');
});

it('laesst eine Aufgabe lesbar, deren Status aus dem Vokabular gefallen ist', function () {
    // Wird ein Status umbenannt und eine Zeile vergessen, muss die Aufgabe ohne
    // Gewicht dastehen — nicht als Seite, die sich nicht mehr oeffnen laesst.
    $task = Task::create(['title' => 'Alte Zeile', 'status' => 'laengst_umbenannt']);

    expect($task->statusDefinition())->toBeNull()
        ->and($task->isTerminal())->toBeFalse()
        ->and($task->urgency())->toBe(0.0);
});

it('findet mit dem offen-Scope die angemeldeten offenen Status', function () {
    Task::create(['title' => 'A', 'status' => 'offen']);
    Task::create(['title' => 'B', 'status' => 'geparkt']);
    Task::create(['title' => 'C', 'status' => 'fertig']);

    // Ohne ORDER BY darf die Datenbank sortieren, wie sie will — geprueft
    // wird, WAS drin ist, nicht in welcher Folge.
    expect(Task::open()->pluck('title')->all())->toEqualCanonicalizing(['A', 'B']);
});

it('zaehlt die offenen Status auf, statt die terminalen auszuschliessen', function () {
    // Der Unterschied zeigt sich erst an kaputten Daten: Ein Status, den
    // niemand angemeldet hat — umbenannt, vertippt, von woanders importiert —
    // waere mit „nicht terminal" in der Liste der offenen Aufgaben gelandet
    // und haette dort wie gewoehnliche Arbeit ausgesehen.
    //
    // Genau so ist Bug #582 im AI Brain entstanden. Die Korrektur dort war
    // dieselbe: aufzaehlen statt verneinen.
    Task::create(['title' => 'Echt offen', 'status' => 'offen']);
    Task::create(['title' => 'Kaputter Wert', 'status' => 'laengst_umbenannt']);

    expect(Task::open()->pluck('title')->all())->toBe(['Echt offen'])
        ->and(app(StatusRegistry::class)->openKeys())->toEqualCanonicalizing(['offen', 'in_arbeit', 'geparkt']);
});
