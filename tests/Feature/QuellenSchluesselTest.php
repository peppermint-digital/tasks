<?php

use Peppermint\Tasks\Exceptions\SourceKeyTaken;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;

/*
| Ein Schlüssel, eine Quelle — Bug #1466.
|
| `register()` ersetzte bis zum 13.11.2026 wortlos. Das hielt genau so lange,
| wie niemand einen Fehler machte, und schwieg in dem Moment, in dem es jemand
| tat: Im Peppermint Manager (#9887) nahm eine zweite Quelle je System die erste
| weg, `forSubjects()` lief leer, und vier Tests eines am selben Tag
| ausgerollten Vorgangs starben — ohne dass irgendwo etwas gemeldet wurde.
|
| Ohne diese Tests wäre der Riegel wieder einer, von dem niemand weiss.
*/

it('sagt laut Bescheid, wenn ein Schlüssel schon belegt ist', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('crm'));

    expect(fn () => $register->register(new ErfundeneQuelle('crm')))
        ->toThrow(SourceKeyTaken::class);
});

it('nennt beide Klassen und den Schlüssel, nicht nur „belegt"', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('crm'));

    try {
        $register->register(new ErfundeneQuelle('crm'));
        $satz = '';
    } catch (SourceKeyTaken $e) {
        $satz = $e->getMessage();
    }

    // „Der Schlüssel crm ist belegt" schickt jemanden suchen. Wer BEIDE
    // Klassen liest, weiss in einer Zeile, was passiert ist.
    expect($satz)->toContain('crm')
        ->and($satz)->toContain(ErfundeneQuelle::class)
        ->and($satz)->toContain('ersetzen: true');
});

it('lässt die vorhandene Quelle stehen, wenn die zweite abgewiesen wird', function () {
    $register = new TaskSourceRegistry;
    $vorhanden = new ErfundeneQuelle('crm');
    $register->register($vorhanden);

    try {
        $register->register(new ErfundeneQuelle('crm'));
    } catch (SourceKeyTaken) {
        // erwartet
    }

    // Der Punkt des Riegels: Der Fehlschlag darf nicht auch noch den Bestand
    // mitnehmen. Was angemeldet war, bleibt angemeldet.
    expect($register->all()['crm'])->toBe($vorhanden);
});

it('ersetzt, wenn es ausdrücklich verlangt wird', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('crm'));

    $neu = new ErfundeneQuelle('crm');
    $register->register($neu, ersetzen: true);

    // Es gibt den Fall — ein Produkt, das eine Paketquelle durch seine eigene
    // austauscht. Er ist nur keine stille Vorgabe mehr.
    expect($register->all()['crm'])->toBe($neu)
        ->and($register->all())->toHaveCount(1);
});

it('lässt verschiedene Schlüssel unberührt nebeneinander stehen', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('crm'));
    $register->register(new ErfundeneQuelle('verwaltung'));

    expect(array_keys($register->all()))->toBe(['crm', 'verwaltung']);
});
