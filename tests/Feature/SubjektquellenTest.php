<?php

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\SourceFailure;
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
 *
 * ## Warum diese Tests am 11.11.2026 umgeschrieben wurden (#9809)
 *
 * Zwei Namen haben sich geaendert, das gepruefte Verhalten nicht:
 *
 * - `SubjectTasks` ist `TaskHarvest`, und `collectForSubject()` antwortet in
 *   derselben Form wie `harvest()`. Es gab beide Formen genau einen Tag lang,
 *   parallel entstanden; eine Maske, die Datensatz- und Personenaufgaben
 *   nebeneinander zeigt, musste zwei Formen lesen, um EINE Frage zu stellen.
 * - `isIncomplete()` ist weg, `complete()` bleibt. Zwei Namen fuer eine Frage
 *   heisst, dass irgendwann nur einer von beiden gepflegt wird — und welcher,
 *   entscheidet der Zufall des naechsten Aufrufs. Jedes `isIncomplete()` hier
 *   ist deshalb ein umgedrehtes `complete()`, Zeile fuer Zeile.
 * - `SubjectSourceFailure` ist `SourceFailure` mit der Reihenfolge
 *   (Quelle, Text, Grund). Der Grund heisst jetzt `reason` und traegt
 *   tatsaechlich den Grund; vorher stand dort der freie Text und der Grund in
 *   `errorClass`.
 *
 * Neu geprueft wird `ok` — welche Systeme GEANTWORTET haben, auch mit nichts.
 * Das fehlte dieser Seite, und ohne es ist „dieser Datensatz hat drueben keine
 * Aufgaben" nicht von „drueben wurde nie gefragt" zu unterscheiden.
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
        ->and($antwort->complete())->toBeTrue();
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
        ->and($antwort->complete())->toBeTrue()
        ->and($antwort->ok)->toBe(['crm']);
});

it('nennt ein System, das zu dem Datensatz NICHTS gemeldet hat, als beantwortet', function () {
    // Die Unterscheidung, um die es in der ganzen Zusammenlegung geht, auf der
    // Datensatz-Seite: Das CRM hat geantwortet, und zwar mit nichts. Stuende
    // das nicht in der Antwort, waere „zu diesem Auftrag liegt im CRM nichts
    // vor" nicht von „das CRM wurde nie gefragt" zu unterscheiden — und nur
    // das erste darf eine Maske als Aussage hinschreiben.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', [
        'auftrag:8' => [fremdaufgabe('crm', 1, 'Gehoert zu einem anderen')],
    ]));

    $antwort = $register->collectForSubject('auftrag', 7);

    expect($antwort->tasks)->toBe([])
        ->and($antwort->ok)->toBe(['crm'])
        ->and($antwort->empty())->toBeTrue('Nichts offen UND alle haben geantwortet.');
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
        'tasksForSubject', 'crm', null, SourceFailure::NOT_ALLOWED,
    )));
    $register->register(new ErfundeneSubjektquelle('manager', [
        'auftrag:7' => [fremdaufgabe('manager', 5, 'Produzieren')],
    ]));

    $antwort = $register->collectForSubject('auftrag', 7);

    // Das Entscheidende: Die eine Aufgabe ist da UND es ist erkennbar, dass
    // etwas fehlt. Ohne das zweite liest sich die Seite als vollstaendig.
    expect($antwort->tasks)->toHaveCount(1)
        ->and($antwort->complete())->toBeFalse()
        ->and($antwort->missingSources())->toBe(['crm'])
        ->and($antwort->failures['crm']->reason)->toBe('not_allowed');
});

it('wirft nicht — eine kaputte Quelle darf die Seite nicht leeren', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', faellt: ForeignSourceFailed::during(
        'tasksForSubject', 'crm', null, SourceFailure::UNREACHABLE,
    )));

    // Nicht werfen, aber auch nicht verschweigen — die beiden Regeln des
    // Pakets widersprechen sich nur scheinbar.
    $antwort = $register->collectForSubject('auftrag', 7);

    expect($antwort->tasks)->toBe([])
        ->and($antwort->complete())->toBeFalse();
});

it('unterscheidet, ob Warten helfen kann', function () {
    // Die Reihenfolge ist (Quelle, TEXT, GRUND) — der zweite Platz ist der
    // Satz, den ein Mensch liest, der dritte einer der vier Gruende. Vorher
    // hiess der zweite `reason` und trug doch den Text; wer die Konstante
    // dorthin schrieb, bekam einen Fehlschlag ohne Grund und keinen Hinweis.
    $wartenHilft = new SourceFailure('crm', 'weg', SourceFailure::UNREACHABLE);
    $wartenHilftNicht = new SourceFailure('crm', 'nein', SourceFailure::NOT_ALLOWED);

    // „Neu laden" bei einer fehlenden Freigabe anzubieten ist ein falsches
    // Versprechen — daran aendert kein Klick etwas.
    expect($wartenHilft->mightPassByItself())->toBeTrue()
        ->and($wartenHilftNicht->mightPassByItself())->toBeFalse();

    // Ohne bekannten Grund wird nichts versprochen.
    expect((new SourceFailure('crm', 'irgendwas'))->mightPassByItself())->toBeFalse();

    // Und ein Grund, den sich die Gegenseite ausgedacht hat, wird keiner: Die
    // vier Namen sind auf der Leitung ein geschlossener Satz. Ein fuenfter
    // Wert erreichte sonst eine Maske, die genau vier Zweige hat — und das ist
    // der Fehler, den das Typisieren beenden sollte.
    expect((new SourceFailure('crm', 'Text', 'boom'))->reason)->toBeNull();
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
    expect($antwort->complete())->toBeFalse();
});

it('fragt eine Quelle nicht, die der Mensch abgeschaltet hat — und meldet das nicht als Fehlen', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', [
        'auftrag:7' => [fremdaufgabe('crm', 1, 'Nachfassen')],
    ]));

    $antwort = $register->collectForSubject('auftrag', 7, skip: ['crm']);

    // Abgeschaltet ist kein Fehlschlag: Niemand wartet auf diese Antwort,
    // also braucht ihr Fehlen keinen Hinweis.
    //
    // Sie steht aber auch nicht in `ok` — „abgeschaltet" ist keine Antwort,
    // und wer sie dort fuehrte, behauptete, das System haette nichts gemeldet.
    expect($antwort->tasks)->toBe([])
        ->and($antwort->complete())->toBeTrue()
        ->and($antwort->ok)->toBe([]);
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
        ->and($antwort->complete())->toBeTrue();
});

it('laesst die Fehlerklasse weg, wenn die Quelle sie nicht kennt', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSubjektquelle('crm', faellt: ForeignSourceFailed::during(
        'tasksForSubject', 'crm',
    )));

    $antwort = $register->collectForSubject('auftrag', 7);

    // Der Grund ist absichtlich optional: Eine Quelle, die die vier nicht
    // auseinanderhalten kann, muss trotzdem einen Fehlschlag melden koennen.
    expect($antwort->failures['crm']->reason)->toBeNull()
        ->and($antwort->complete())->toBeFalse();
});
