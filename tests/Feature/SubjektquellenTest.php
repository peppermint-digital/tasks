<?php

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\SubjectSourceFailure;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;
use Peppermint\Tasks\Tests\Support\ErfundeneSubjektquelle;

/*
 * Aufgaben zu EINEM Datensatz eines anderen Systems (#9669).
 *
 * Die Frage am Auftrag ist nicht „welche Aufgaben hat diese Person", sondern
 * „was gehoert zu diesem Datensatz". Gepruefte Zusicherung dabei: Eine
 * unvollstaendige Antwort muss sich von einer vollstaendigen unterscheiden —
 * sonst liest sich „drei von fuenf" wie „genau drei".
 */

function fremdaufgabe(string $quelle, int $id, string $titel): ForeignTask
{
    return new ForeignTask(sourceKey: $quelle, id: $id, title: $titel);
}

it('fragt nur Quellen, die Datensaetze beantworten', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('alt'));
    $register->register(new ErfundeneSubjektquelle('neu'));

    expect(array_keys($register->forSubjects()))->toBe(['neu']);

    // Gegenprobe: Die alte Quelle ist deshalb nicht verschwunden — sie
    // beantwortet weiter die Personenfrage.
    expect(array_keys($register->available()))->toBe(['alt', 'neu']);
});

it('bricht nicht ab, weil eine alte Quelle die Methode nicht kennt', function () {
    // Der Grund, warum tasksForSubject NICHT abstrakt ist: Jede bestehende
    // Quelle in jedem Produkt waere beim naechsten composer update tot.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('alt'));

    $antwort = $register->collectForSubject('auftrag', 7);

    expect($antwort->tasks)->toBe([])
        ->and($antwort->isIncomplete())->toBeFalse();
});

it('sagt laut Bescheid, wenn eine Quelle ohne Subjekt-Unterstuetzung doch gefragt wird', function () {
    // Eine leere Liste waere hier das Schlimmste: Sie liesse sich nicht von
    // „dieser Datensatz hat drueben keine Aufgaben" unterscheiden.
    expect(fn () => (new ErfundeneQuelle('alt'))->tasksForSubject('auftrag', 7))
        ->toThrow(LogicException::class, 'supportsSubjects');
});

it('holt die Aufgaben zum genannten Datensatz und nicht zu einem anderen', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', [
        'deal:4312' => [fremdaufgabe('crm', 1, 'Nachfassen')],
        'deal:9999' => [fremdaufgabe('crm', 2, 'Fremder Vorgang')],
    ]));

    $antwort = $register->collectForSubject('deal', 4312);

    expect($antwort->tasks)->toHaveCount(1)
        ->and($antwort->tasks[0]->title)->toBe('Nachfassen')
        ->and($antwort->isIncomplete())->toBeFalse();
});

it('fuehrt die Aufgaben mehrerer Systeme zusammen', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', [
        'auftrag:7' => [fremdaufgabe('crm', 1, 'Nachfassen')],
    ]));
    $register->register(new ErfundeneSubjektquelle('manager', [
        'auftrag:7' => [fremdaufgabe('manager', 5, 'Produzieren'), fremdaufgabe('manager', 6, 'Bestellen')],
    ]));

    expect($register->collectForSubject('auftrag', 7)->tasks)->toHaveCount(3);
});

it('gibt einen Fehlschlag als Angabe zurueck, nicht als leere Liste', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', faellt: ForeignSourceFailed::during(
        'tasksForSubject', 'crm', null, SubjectSourceFailure::NOT_ALLOWED,
    )));
    $register->register(new ErfundeneSubjektquelle('manager', [
        'auftrag:7' => [fremdaufgabe('manager', 5, 'Produzieren')],
    ]));

    $antwort = $register->collectForSubject('auftrag', 7);

    // Das Entscheidende: Die eine Aufgabe ist da UND es ist erkennbar, dass
    // etwas fehlt. Ohne das zweite liest sich die Seite als vollstaendig.
    expect($antwort->tasks)->toHaveCount(1)
        ->and($antwort->isIncomplete())->toBeTrue()
        ->and($antwort->missingSources())->toBe(['crm'])
        ->and($antwort->failures['crm']->errorClass)->toBe('not_allowed');
});

it('wirft nicht — eine kaputte Quelle darf die Seite nicht leeren', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', faellt: ForeignSourceFailed::during(
        'tasksForSubject', 'crm', null, SubjectSourceFailure::UNREACHABLE,
    )));

    // Nicht werfen, aber auch nicht verschweigen — die beiden Regeln des
    // Pakets widersprechen sich nur scheinbar.
    $antwort = $register->collectForSubject('auftrag', 7);

    expect($antwort->tasks)->toBe([])
        ->and($antwort->isIncomplete())->toBeTrue();
});

it('unterscheidet, ob Warten helfen kann', function () {
    $wartenHilft = new SubjectSourceFailure('crm', 'weg', SubjectSourceFailure::UNREACHABLE);
    $wartenHilftNicht = new SubjectSourceFailure('crm', 'nein', SubjectSourceFailure::NOT_ALLOWED);

    // „Neu laden" bei einer fehlenden Freigabe anzubieten ist ein falsches
    // Versprechen — daran aendert kein Klick etwas.
    expect($wartenHilft->mightPassByItself())->toBeTrue()
        ->and($wartenHilftNicht->mightPassByItself())->toBeFalse();

    // Ohne bekannte Fehlerklasse wird nichts versprochen.
    expect((new SubjectSourceFailure('crm', 'irgendwas'))->mightPassByItself())->toBeFalse();
});

it('meldet auch einen Defekt auf unserer Seite als Fehlschlag', function () {
    $register = new TaskSourceRegistry;
    $register->register(new class extends ErfundeneSubjektquelle
    {
        public function tasksForSubject(string $type, string|int $id): array
        {
            throw new RuntimeException('Tippfehler im Produkt');
        }
    });

    $antwort = $register->collectForSubject('auftrag', 7);

    // Auch das darf nicht als „nichts gefunden" durchgehen.
    expect($antwort->isIncomplete())->toBeTrue();
});

it('fragt eine Quelle nicht, die der Mensch abgeschaltet hat — und meldet das nicht als Fehlen', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', [
        'auftrag:7' => [fremdaufgabe('crm', 1, 'Nachfassen')],
    ]));

    $antwort = $register->collectForSubject('auftrag', 7, skip: ['crm']);

    // Abgeschaltet ist kein Fehlschlag: Niemand wartet auf diese Antwort,
    // also braucht ihr Fehlen keinen Hinweis.
    expect($antwort->tasks)->toBe([])
        ->and($antwort->isIncomplete())->toBeFalse();
});

it('fragt eine Quelle ohne Zugriff gar nicht', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', [
        'auftrag:7' => [fremdaufgabe('crm', 1, 'Nachfassen')],
    ], verfuegbar: false));

    $antwort = $register->collectForSubject('auftrag', 7);

    // Wer keinen CRM-Zugriff hat, hat keine CRM-Aufgaben — das ist die
    // richtige Antwort, kein Fehlerzustand.
    expect($antwort->tasks)->toBe([])
        ->and($antwort->isIncomplete())->toBeFalse();
});

it('laesst die Fehlerklasse weg, wenn die Quelle sie nicht kennt', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', faellt: ForeignSourceFailed::during(
        'tasksForSubject', 'crm',
    )));

    $antwort = $register->collectForSubject('auftrag', 7);

    // Die Klasse ist absichtlich optional: Eine Quelle, die sie nicht
    // auseinanderhalten kann, muss trotzdem einen Fehlschlag melden koennen.
    expect($antwort->failures['crm']->errorClass)->toBeNull()
        ->and($antwort->isIncomplete())->toBeTrue();
});
