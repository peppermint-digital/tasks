<?php

use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;

function fremdeAufgabe(string $titel = 'Fremd'): ForeignTask
{
    return new ForeignTask(sourceKey: 'fremd', id: 42, title: $titel, status: 'todo', urgency: 9.5);
}

it('sammelt die Aufgaben aller angemeldeten Quellen', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('fremd', [fremdeAufgabe('A'), fremdeAufgabe('B')]));

    expect($register->collect(1))->toHaveCount(2);
});

it('fragt eine abgeschaltete Quelle gar nicht erst', function () {
    // Nicht bloss ausblenden: Eine fremde Quelle bedeutet einen Aufruf an ein
    // anderes System, und der soll unterbleiben, wenn niemand das Ergebnis
    // sehen will.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('fremd', [fremdeAufgabe()], faellt: true));

    // Waere sie gefragt worden, haette sie geworfen.
    expect($register->collect(1, skip: ['fremd']))->toBeEmpty();
});

it('laesst eine unerreichbare Quelle die Liste nicht mitreissen', function () {
    // Eine Seite, die nichts zeigt, weil der Server eines anderen Systems
    // steht, ist schlimmer als eine Seite ohne ein Filter-Chip.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('kaputt', faellt: true));
    $register->register(new ErfundeneQuelle('heil', [fremdeAufgabe('Kommt durch')]));

    $ergebnis = $register->collect(1);

    expect($ergebnis)->toHaveCount(1)
        ->and($ergebnis[0]->title)->toBe('Kommt durch');
});

it('stellt der fremden Kennung die Quelle voran', function () {
    // Sonst liesse sich eine fremde Aufgabe fuer eine eigene halten — und ein
    // Griff daneben oeffnet die falsche oder aendert sie gar.
    $daten = fremdeAufgabe()->toArray();

    expect($daten['id'])->toBe('fremd:42')
        ->and($daten['foreign_id'])->toBe(42)
        ->and($daten['external'])->toBeTrue();
});

it('traegt den Dringlichkeitswert, aber nicht die Aufschluesselung', function () {
    // Die Zahl laesst sich vergleichen. Die Aufschluesselung nicht: Ihre
    // Faktoren beziehen sich auf Dinge, die dieses System nicht hat —
    // Zeitbloecke, Blocker, Kommentare des anderen Systems.
    $daten = fremdeAufgabe()->toArray();

    expect($daten['urgency'])->toBe(9.5)
        ->and($daten)->not->toHaveKey('factors');
});
