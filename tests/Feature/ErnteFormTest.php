<?php

use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\RemoteResult;
use Peppermint\Tasks\Sources\RemoteTaskSource;
use Peppermint\Tasks\Sources\SourceFailure;
use Peppermint\Tasks\Sources\TaskHarvest;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;
use Peppermint\Tasks\Tests\Support\ErfundeneSubjektquelle;
use Peppermint\Tasks\Tests\Support\ErfundenesSystem;

/*
| Die EINE Ernte-Form, fuer beide Fragen (#9809).
|
| Am 11.11.2026 gab es sie zweimal: `TaskHarvest` fuer „die Aufgaben dieser
| Person je System" und `SubjectTasks` fuer „die Aufgaben dieses Datensatzes je
| System". Parallel entstanden, beide fuer sich gruen, und jede hatte etwas,
| das der anderen fehlte — die eine `ok` und `empty()`, die andere die
| typisierten Ausfallgruende.
|
| Diese Datei haelt fest, was die Zusammenlegung erreicht hat, und zwar an den
| Stellen, an denen sie wieder zerfallen koennte:
|
| - Die vier Gruende, EINZELN. Ein Test, der nur „irgendein Ausfall" prueft,
|   ist der, der die Typisierung spaeter wegfallen laesst.
| - Beide Abfragen antworten in derselben Klasse.
| - `collect()` fragt weiter nur die Personen-Quellen.
| - Die Drahtform, Schluessel fuer Schluessel — sie ist der Vertrag, an dem die
|   TS-Seite im Kalenderpaket haengt.
*/

/**
 * Ein Register mit genau einer Quelle, die mit dem gegebenen Grund ausfaellt.
 *
 * Ueber `RemoteResult` und nicht ueber einen handgeschriebenen Wurf: Das ist
 * der Weg, den die Gruende in den Produkten tatsaechlich nehmen — die
 * Gegenstelle nennt die Klasse, der Aufrufweg traegt sie, die Quelle wirft sie,
 * die Ernte legt sie ab. Ein direkt gebauter Wurf wuerde drei dieser vier
 * Stufen ueberspringen und genau dort nichts beweisen.
 */
function ernteMitGrund(?string $grund): TaskHarvest
{
    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new ErfundenesSystem('crm', antworten: [
        'tasks.list' => RemoteResult::failed('Das CRM sagt nein', $grund),
    ])));

    return $register->harvest(1);
}

it('haelt „bietet es nicht an" als eigenen Grund fest', function () {
    // Kein Ausfall im Sinne von „kaputt": Das System kann diesen Vorgang
    // einfach nicht. Eine Maske, die das als Fehler zeigt, meldet einen Fehler,
    // wo keiner ist — und jemand sucht in einem fremden Protokoll nach einer
    // Ursache, die es nicht gibt.
    $ausfall = ernteMitGrund(SourceFailure::NOT_OFFERED)->failures['crm'];

    expect($ausfall->reason)->toBe('not_offered')
        ->and($ausfall->mightPassByItself())->toBeFalse('Warten aendert daran nichts.');
});

it('haelt „du darfst nicht" als eigenen Grund fest', function () {
    // Der teuerste der vier, wenn er verwechselt wird: Als „nicht erreichbar"
    // angezeigt laedt er zum Wiederholen ein, und kein Wiederholen der Welt
    // beschafft eine Freigabe. Was hilft, ist nachfragen — und das kann eine
    // Maske nur vorschlagen, wenn sie den Grund kennt.
    $ausfall = ernteMitGrund(SourceFailure::NOT_ALLOWED)->failures['crm'];

    expect($ausfall->reason)->toBe('not_allowed')
        ->and($ausfall->mightPassByItself())->toBeFalse();
});

it('haelt „nicht erreichbar" als eigenen Grund fest', function () {
    // Der einzige, bei dem „gleich nochmal" ein ehrliches Angebot ist.
    $ausfall = ernteMitGrund(SourceFailure::UNREACHABLE)->failures['crm'];

    expect($ausfall->reason)->toBe('unreachable')
        ->and($ausfall->mightPassByItself())->toBeTrue();
});

it('haelt „das Werkzeug drueben ist gescheitert" als eigenen Grund fest', function () {
    // Es hat GEANTWORTET, und die Antwort war ein Fehler. Das unterscheidet
    // ihn von „nicht erreichbar": Hier gibt es drueben etwas zu lesen.
    $ausfall = ernteMitGrund(SourceFailure::TOOL_FAILED)->failures['crm'];

    expect($ausfall->reason)->toBe('tool_failed')
        ->and($ausfall->mightPassByItself())->toBeTrue();
});

it('laesst den Grund leer, wenn die Gegenstelle keinen genannt hat', function () {
    // Der fuenfte Zustand, und er ist echt: Eine Quelle, die die vier nicht
    // auseinanderhalten kann, muss trotzdem einen Ausfall melden duerfen.
    // Hier einen Grund zu erfinden waere die teurere Luege, weil die Maske auf
    // ihm handelt.
    $ausfall = ernteMitGrund(null)->failures['crm'];

    expect($ausfall->reason)->toBeNull()
        ->and($ausfall->message)->toContain('Das CRM sagt nein')
        ->and($ausfall->mightPassByItself())->toBeFalse('Nichts versprechen, was niemand benannt hat.');
});

it('gibt beiden Fragen DIESELBE Form zurueck', function () {
    // Der Kern der Zusammenlegung. Eine Maske, die die Aufgaben einer Person
    // und die eines Datensatzes nebeneinander zeigt, liest eine Form — nicht
    // zwei. Mit zwei Formen landet der Hinweis „unvollstaendig" irgendwann an
    // der falschen Haelfte, und das faellt niemandem auf, weil beide Haelften
    // fuer sich stimmen.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('person-system', [
        new ForeignTask(sourceKey: 'person-system', id: 1, title: 'Meine'),
    ]));
    $register->register(new ErfundeneSubjektquelle('datensatz-system', [
        'auftrag:7' => [new ForeignTask(sourceKey: 'datensatz-system', id: 2, title: 'Zum Auftrag')],
    ], beantwortetPersonen: false));

    $person = $register->harvest(1);
    $datensatz = $register->collectForSubject('auftrag', 7);

    expect($person)->toBeInstanceOf(TaskHarvest::class)
        ->and($datensatz)->toBeInstanceOf(TaskHarvest::class)
        // Und nicht nur dieselbe Klasse, sondern dieselben beantworteten
        // Fragen: beide wissen, wer geantwortet hat, beide koennen „wirklich
        // nichts offen" sagen.
        ->and($person->ok)->toBe(['person-system'])
        ->and($datensatz->ok)->toBe(['datensatz-system'])
        ->and($person->complete())->toBeTrue()
        ->and($datensatz->complete())->toBeTrue()
        ->and($person->empty())->toBeFalse()
        ->and($datensatz->empty())->toBeFalse()
        ->and(array_keys($person->toArray()))->toBe(array_keys($datensatz->toArray()));
});

it('fragt in collect() weiter nur die Personen-Quellen', function () {
    // Das Detail, das die Zusammenlegung beinahe verloren haette: Auf dem
    // fremden Stand lief `collect()` ueber `forPersons()`, unsere `harvest()`
    // ueber `available()`. Zusammengelegt gibt es nur einen Rumpf — laeuft er
    // ueber `available()`, fragt `collect()` ploetzlich Quellen, die die
    // Personenfrage nicht beantworten: ein Aufruf ueber die Systemgrenze fuer
    // eine Frage, die das andere System nicht haelt, und ein Eintrag in `ok`,
    // der eine Antwort behauptet.
    //
    // Kein Test hat das gehalten — deshalb dieser.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('person-system', [
        new ForeignTask(sourceKey: 'person-system', id: 1, title: 'Meine'),
    ]));
    $register->register(new ErfundeneSubjektquelle('nur-datensaetze', beantwortetPersonen: false));

    $ernte = $register->harvest(1);

    expect($ernte->ok)->toBe(['person-system'])
        ->and($register->collect(1))->toHaveCount(1)
        // Gegenprobe, damit der Test nicht bloss „sie ist nicht angemeldet"
        // beweist: Fuer die Datensatz-Frage ist sie sehr wohl da.
        ->and(array_keys($register->forSubjects()))->toBe(['nur-datensaetze']);
});

it('haelt die Drahtform fest, auf die die Oberflaeche sich verlaesst', function () {
    // Diese Form reist aus dem Produkt heraus in die Maske — im Kalenderpaket
    // haengt ein veroeffentlichter TS-Typ daran. Was hier steht, steht dort,
    // und ein stilles Umbenennen auf einer der beiden Seiten ist genau der
    // Fehler, den niemand sieht, bis eine Spalte leer bleibt.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('heil', [
        new ForeignTask(sourceKey: 'heil', id: 7, title: 'Kommt durch'),
    ]));
    $register->register(new RemoteTaskSource(new ErfundenesSystem('crm', antworten: [
        'tasks.list' => RemoteResult::failed('Keine Freigabe', SourceFailure::NOT_ALLOWED),
    ])));

    $draht = $register->harvest(1)->toArray();

    expect(array_keys($draht))->toBe(['tasks', 'ok', 'failures', 'complete'])
        ->and($draht['ok'])->toBe(['heil'])
        ->and($draht['complete'])->toBeFalse()
        ->and($draht['failures']['crm'])->toBe([
            'source' => 'crm',
            'reason' => 'not_allowed',
            'message' => 'Fremdes System nicht erreichbar: Keine Freigabe',
            // Reist mit, statt drueben aus dem Grund neu abgeleitet zu werden:
            // Die Regel „Warten hilft" gehoert zu den vier Gruenden, und zwei
            // Orte, an denen sie steht, sind einer zu viel.
            'may_retry' => false,
        ]);
});
