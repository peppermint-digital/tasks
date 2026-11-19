<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\Tasks\Sources\BrueckenSubjektquelle;
use Peppermint\Tasks\Sources\BrueckenSubjektquellen;
use Peppermint\Tasks\Sources\SourceFailure;
use Peppermint\Tasks\Sources\TaskHarvest;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;
use Peppermint\Tasks\Tests\Support\Person;

/*
 * Die Nabe ist beim ANMELDEN weg — und die Ernte sagt es (Bug #1513).
 *
 * ## Was vorher war, gemessen am 19.11.2026
 *
 * Mit dem echten Client gegen einen geschlossenen Port: kein Eintrag im
 * Register, `plannable()` leer, und `harvest()` meldete `complete` mit leerem
 * `failures`. In vier Lagen (Nabe weg, Nabe antwortet Fehler, Freigabe fehlt,
 * Nabe schluckt Pakete) dieselbe Antwort wie in der einen, in der es wirklich
 * niemanden gibt. Eine Liste, die kürzer war als sie sein sollte, nannte sich
 * vollständig.
 *
 * ## Was hier festgenagelt wird
 *
 * Dieselben Lagen, jetzt als Tests: Jeder Ausfall bringt einen Grund UND
 * `complete === false`. Die echt leere Lage C bleibt leer und vollständig —
 * sie ist der Test, der am meisten beweist: Echt leer darf nicht plötzlich
 * als Ausfall gelten.
 *
 * ## Warum hier KEINE Attrappe von `AiBrain` steht
 *
 * Die übrigen Dateien ersetzen die Fassade. Hier läuft der echte
 * `GatewayClient` aus der Brücke, nur die Leitung ist nachgestellt — so wie
 * in der Messung. Sonst prüfte der Test, was die Attrappe zurückgibt, und
 * nicht, was die Brücke aus einer kaputten Leitung macht. Das Token liegt
 * vorab im Cache, damit die Anbieterfrage der einzige Gang über die Leitung
 * ist, der zählt.
 */

const NABE_ADRESSE = 'https://nabe.test';

/** Eine eingerichtete Brücke mit echtem Client, jemand davor, das Register aus der Konfiguration. */
function nabeAufbau(string $adresse = NABE_ADRESSE): void
{
    Bruecke::vergessen();
    AiBrain::clearResolvedInstances();
    Cache::put('ai_brain_bridge.oauth_token', 'tok', 600);

    $tokens = new OAuthTokenProvider([]);

    app()->instance(AiBrainManager::class, new AiBrainManager(
        config: ['base_url' => $adresse, 'gateway' => ['timeout' => 1]],
        tokens: $tokens,
        publisher: new EventPublisher([], $tokens, 'tasks-test'),
    ));

    config()->set('tasks.sources', [BrueckenSubjektquellen::class]);
    app()->forgetInstance(TaskSourceRegistry::class);
    app()->forgetScopedInstances();

    test()->actingAs(new Person(['id' => 1, 'name' => 'Bastian']));
}

/**
 * Die Anbieterfrage beantwortet je Vorgang, und der Gateway antwortet leer.
 *
 * @param  array<string, mixed>  $anbieter  Vorgang => Antwort (oder `*` für alle)
 */
function nabeAntwortet(array $anbieter): void
{
    $faelschungen = [];

    foreach ($anbieter as $vorgang => $antwort) {
        $muster = $vorgang === '*' ? '*' : $vorgang.'/providers';
        $faelschungen['nabe.test/api/v1/capabilities/'.$muster] = $antwort;
    }

    // Wer gefragt wird, hat nichts offen — es geht hier nicht um Aufgaben,
    // sondern darum, ob gefragt werden KONNTE.
    $faelschungen['nabe.test/api/v1/gateway'] = Http::response(['ok' => true, 'data' => []]);

    Http::fake($faelschungen);
}

function nabeErnte(): TaskHarvest
{
    return app(TaskSourceRegistry::class)->harvest(1);
}

function nabeAkte(): TaskHarvest
{
    return app(TaskSourceRegistry::class)->collectForSubject('order', 7);
}

/** Der Ausfall unter dem Schlüssel der Nabe — oder null. */
function nabeAusfall(TaskHarvest $ernte): ?SourceFailure
{
    return $ernte->failures[BrueckenSubjektquellen::NABE] ?? null;
}

afterEach(fn () => Bruecke::vergessen());

// --- Die Lagen aus der Messung ------------------------------------------------

it('A: Nabe weg (Port zu) — Grund unreachable, Ernte unvollständig', function () {
    // Kein Http::fake: Die Verbindung wird wirklich abgelehnt.
    nabeAufbau('http://127.0.0.1:9');

    $register = app(TaskSourceRegistry::class);
    $ernte = nabeErnte();

    expect($register->all())->toBe([])
        ->and($register->plannable())->toBe([])
        ->and($ernte->complete())->toBeFalse()
        ->and($ernte->tasks)->toBe([])
        ->and(nabeAusfall($ernte)?->reason)->toBe(SourceFailure::UNREACHABLE)
        ->and(nabeAusfall($ernte)?->message)->toContain('AI Brain nicht erreichbar')
        ->and(nabeAusfall($ernte)?->mightPassByItself())->toBeTrue()
        ->and(nabeAkte()->complete())->toBeFalse();
});

it('B: Nabe antwortet mit 500 ohne JSON — Grund tool_failed', function () {
    nabeAufbau();
    nabeAntwortet(['*' => Http::response('kaputt', 500)]);

    $ernte = nabeErnte();

    expect($ernte->complete())->toBeFalse()
        ->and(nabeAusfall($ernte)?->reason)->toBe(SourceFailure::TOOL_FAILED)
        ->and(nabeAusfall($ernte)?->message)->toContain('HTTP 500');
});

it('C: Nabe antwortet, niemand bietet Aufgaben an — leer UND vollständig', function () {
    // Der Test, der am meisten beweist. Hier darf KEIN Ausfall erscheinen:
    // „es gibt keine fremden Systeme" ist eine Auskunft, kein Fehler, und eine
    // Maske soll dafür keinen Warnhinweis zeigen.
    nabeAufbau();
    nabeAntwortet(['*' => Http::response(['ok' => true, 'providers' => []])]);

    $register = app(TaskSourceRegistry::class);
    $ernte = nabeErnte();
    $akte = nabeAkte();

    expect($register->all())->toBe([])
        ->and($ernte->failures)->toBe([])
        ->and($ernte->complete())->toBeTrue()
        ->and($ernte->empty())->toBeTrue()
        ->and($akte->failures)->toBe([])
        ->and($akte->complete())->toBeTrue()
        ->and($register->registrationFailures('persons'))->toBe([])
        ->and($register->registrationFailures('subjects'))->toBe([]);
});

it('D: Freigabe fehlt — Brain sagt not_allowed, und das kommt an', function () {
    // So antwortet AI Brain wirklich (CapabilityProvidersApiController): 403
    // mit `not_allowed`. Bis Bug #1513 wurde das verworfen.
    nabeAufbau();
    nabeAntwortet(['*' => Http::response([
        'ok' => false,
        'error' => 'not_allowed',
        'message' => 'Fuer „tasks.list" ist dieses System nicht freigeschaltet.',
    ], 403)]);

    $ernte = nabeErnte();

    expect($ernte->complete())->toBeFalse()
        ->and(nabeAusfall($ernte)?->reason)->toBe(SourceFailure::NOT_ALLOWED)
        ->and(nabeAusfall($ernte)?->message)->toContain('nicht freigeschaltet')
        // Warten hilft hier nicht — jemand muss freischalten. Genau dafür
        // braucht die Maske den Grund und nicht nur „unvollständig".
        ->and(nabeAusfall($ernte)?->mightPassByItself())->toBeFalse();
});

it('D2: Absage ohne Namen (Passport 401) — Ausfall ohne Grund, nicht geraten', function () {
    nabeAufbau();
    nabeAntwortet(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $ernte = nabeErnte();

    expect($ernte->complete())->toBeFalse()
        ->and(nabeAusfall($ernte)?->reason)->toBeNull()
        ->and(nabeAusfall($ernte)?->message)->toBe('Unauthenticated.');
});

it('E: Gegenprobe — Nabe nennt ein System, die Ernte ist vollständig', function () {
    nabeAufbau();
    nabeAntwortet(['*' => Http::response(['ok' => true, 'providers' => [
        ['slug' => 'peppermint-manager', 'name' => 'Peppermint Manager', 'capabilities' => ['tasks.list', 'tasks.subject.list', 'tasks.schedule.create']],
    ]])]);

    $register = app(TaskSourceRegistry::class);
    $ernte = nabeErnte();

    expect(array_keys($register->all()))->toBe(['manager'])
        ->and(array_keys($register->plannable()))->toBe(['manager'])
        ->and($ernte->ok)->toBe(['manager'])
        ->and($ernte->failures)->toBe([])
        ->and($ernte->complete())->toBeTrue()
        ->and(nabeAkte()->complete())->toBeTrue();
});

it('F: Nabe schluckt Pakete (Zeitgrenze) — ebenso unreachable', function () {
    // Die Zeitgrenze selbst wird nicht abgewartet: Wie die Brücke eine
    // abgelaufene Zeitgrenze sieht, ist eine ConnectionException — dieselbe
    // Klasse, die Guzzle dort wirft. Gemessen wurde die echte Zeitgrenze am
    // 19.11.2026 (zweimal 3 s bei `gateway.timeout` = 3).
    nabeAufbau();
    nabeAntwortet(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds')]);

    $ernte = nabeErnte();

    expect($ernte->complete())->toBeFalse()
        ->and(nabeAusfall($ernte)?->reason)->toBe(SourceFailure::UNREACHABLE)
        ->and(nabeAusfall($ernte)?->message)->toContain('timed out');
});

// --- Nach Frage getrennt ------------------------------------------------------

it('meldet nur die Liste unvollständig, deren Frage ausgefallen ist', function () {
    // Personen-Frage fällt aus, Datensatz-Frage antwortet. Ein Warnhinweis an
    // der vollständigen Datensatz-Liste wäre die umgekehrte Lüge.
    nabeAufbau();
    nabeAntwortet([
        BrueckenSubjektquelle::CAPABILITY_PERSON => Http::response(['ok' => false, 'error' => 'not_allowed'], 403),
        BrueckenSubjektquelle::CAPABILITY => Http::response(['ok' => true, 'providers' => [
            ['slug' => 'peppermint-crm', 'name' => 'Peppermint CRM'],
        ]]),
    ]);

    $register = app(TaskSourceRegistry::class);

    expect(array_keys($register->all()))->toBe(['crm'])
        ->and(nabeErnte()->complete())->toBeFalse()
        ->and(nabeAusfall(nabeErnte())?->reason)->toBe(SourceFailure::NOT_ALLOWED)
        ->and(nabeAkte()->complete())->toBeTrue()
        ->and(nabeAkte()->ok)->toBe(['crm']);
});

it('laesst den Ausfall weg, wenn die Nabe ausdrücklich abgewählt ist', function () {
    // Dieselbe Regel wie für jede Quelle: Abgewählt heisst, niemand wartet
    // auf diese Antwort, also braucht ihr Fehlen keinen Hinweis.
    nabeAufbau();
    nabeAntwortet(['*' => Http::response('kaputt', 500)]);

    $register = app(TaskSourceRegistry::class);

    expect($register->harvest(1, [BrueckenSubjektquellen::NABE])->complete())->toBeTrue()
        ->and($register->harvest(1)->complete())->toBeFalse();
});

it('fragt auch im Ausfall jeden Vorgang nur einmal je Anfrage', function () {
    // Der Ausfall wird mit der Antwort gemerkt (AnbieterMerker, Bug #1469).
    // Würde er es nicht, kostete ausgerechnet die ausgefallene Nabe jede
    // weitere Frage die volle Zeitgrenze.
    nabeAufbau();
    nabeAntwortet(['*' => Http::response('kaputt', 500)]);

    $register = app(TaskSourceRegistry::class);
    $register->harvest(1);
    $register->collectForSubject('order', 7);
    $register->forPersons();

    Http::assertSentCount(2);
});

// --- Der Schlüssel der Nabe (Bug #1523) ------------------------------------

/*
 * Der Schlüssel der Nabe bezeichnet NUR die Nabe (Bug #1523).
 *
 * ## Was vorher war
 *
 * Der Platzhalter, unter dem ein Ausfall beim Anmelden in der Ernte steht,
 * hiess `ai-brain`. Der Manager führt unter demselben Schlüssel eine EIGENE
 * Aufgabenquelle (`AiBrainTaskSource`, die Brain-Aufgaben der Person). Drei
 * Folgen, alle lautlos:
 *
 * | Lage | Folge |
 * |---|---|
 * | eigene Quelle antwortet, Nabe fällt beim Anmelden aus | `ai-brain` unter `ok` UND unter `failures` |
 * | eigene Quelle fällt beim Ernten aus | ihr Ausfall verdeckt den der Nabe (`??=`) |
 * | Person/Maske wählt die eigene Quelle ab | der Nabe-Ausfall verschwindet mit |
 *
 * Die eigene Quelle wird hier nachgestellt, wie der Manager sie führt: eine
 * Quelle des Registers mit dem Schlüssel `ai-brain`, neben den
 * Brücken-Quellen. Die Nabe fällt mit einer 500 aus — die Lage, in der die
 * Ernte den Anmelde-Ausfall trägt (siehe `NabeBeimAnmeldenTest`).
 *
 * ## Und die Regel, die die Umbenennung erst richtig macht
 *
 * Ohne den Zufall des gleichen Namens hätte der Manager den Nabe-Ausfall auch
 * dann gezeigt, wenn er NUR das eigene System fragt — `skip` = alle Schlüssel
 * des Registers. Eine Ernte, die abgewählt und niemanden übrig gelassen hat,
 * braucht die Nabe nicht. Die Tests 4 und 5 halten beide Seiten fest.
 */

/** Die eigene Quelle des Managers, nachgestellt — gleicher Schlüssel, gleiche Frage. */
function schluesselEigeneQuelle(bool $faellt = false): void
{
    app(TaskSourceRegistry::class)->register(new ErfundeneQuelle('ai-brain', [], $faellt));
}

function schluesselNabeFaelltAus(): void
{
    nabeAufbau();
    nabeAntwortet(['*' => Http::response('kaputt', 500)]);
}

/**
 * 1. Der Kern des Bugs: ein Schlüssel in `ok` und in `failures`.
 */
it('fuehrt die Nabe nicht unter dem Schluessel einer Quelle, die geantwortet hat', function () {
    schluesselNabeFaelltAus();
    schluesselEigeneQuelle();

    $ernte = app(TaskSourceRegistry::class)->harvest(1);

    expect($ernte->ok)->toBe(['ai-brain'])
        ->and(array_keys($ernte->failures))->toBe([BrueckenSubjektquellen::NABE])
        // Der allgemeine Satz, nicht nur dieser Fall: Kein Schlüssel steht in
        // beiden Listen.
        ->and(array_intersect($ernte->ok, array_keys($ernte->failures)))->toBe([])
        ->and($ernte->complete())->toBeFalse();
});

/**
 * 2. Fällt die eigene Quelle beim Ernten aus, stehen BEIDE Ausfälle da.
 *
 * Vorher galt `??=`: Der Ernte-Ausfall von `ai-brain` ging vor, und der
 * Anmelde-Ausfall der Nabe unter demselben Schlüssel fiel weg. Zwei Gründe,
 * zwei Zeilen — die Person soll beide lesen können.
 */
it('verdeckt den Nabe-Ausfall nicht mit dem Ausfall der eigenen Quelle', function () {
    schluesselNabeFaelltAus();
    schluesselEigeneQuelle(faellt: true);

    $ernte = app(TaskSourceRegistry::class)->harvest(1);

    expect(array_keys($ernte->failures))->toEqualCanonicalizing(['ai-brain', BrueckenSubjektquellen::NABE]);
});

/**
 * 3. Wer die eigene Quelle abwählt, wählt die Nabe nicht mit ab.
 */
it('laesst den Nabe-Ausfall stehen, wenn nur die eigene Quelle abgewaehlt ist', function () {
    schluesselNabeFaelltAus();
    schluesselEigeneQuelle();
    app(TaskSourceRegistry::class)->register(new ErfundeneQuelle('verwaltung'));

    $ernte = app(TaskSourceRegistry::class)->harvest(1, ['ai-brain']);

    expect($ernte->ok)->toBe(['verwaltung'])
        ->and(array_keys($ernte->failures))->toBe([BrueckenSubjektquellen::NABE]);
});

/**
 * 4. Nur das Eigene gefragt — dann ist der Nabe-Ausfall belanglos.
 *
 * So fragt der Manager, wenn in der Maske das eigene System gewählt ist:
 * `skip` = alle Schlüssel des Registers. Diese Ernte hat das Register nicht
 * gebraucht und darf sich vollständig nennen; sonst verlöre die Maske ihr
 * „hier ist nichts offen" für Aufgaben, die die Nabe nie berührt haben.
 */
it('meldet den Nabe-Ausfall nicht, wenn die Ernte alles abgewaehlt und niemanden gefragt hat', function () {
    schluesselNabeFaelltAus();
    schluesselEigeneQuelle();

    $register = app(TaskSourceRegistry::class);
    $ernte = $register->harvest(1, array_keys($register->all()));

    expect($ernte->ok)->toBe([])
        ->and($ernte->failures)->toBe([])
        ->and($ernte->complete())->toBeTrue();
});

/**
 * 5. Die Gegenprobe zu 4: Ist die Nabe ganz weg, steht oft NIEMAND im
 * Register. Dann hat auch niemand etwas abgewählt — und gerade dann ist der
 * Ausfall die ganze Auskunft (#1513). Ein `skip` mit Schlüsseln, die es gar
 * nicht gibt, ändert daran nichts.
 */
it('meldet den Nabe-Ausfall weiter, wenn das Register leer ist, auch mit skip', function () {
    schluesselNabeFaelltAus();

    $register = app(TaskSourceRegistry::class);

    expect($register->all())->toBe([])
        ->and(array_keys($register->harvest(1)->failures))->toBe([BrueckenSubjektquellen::NABE])
        ->and(array_keys($register->harvest(1, ['crm'])->failures))->toBe([BrueckenSubjektquellen::NABE])
        ->and(array_keys($register->collectForSubject('order', 7, ['crm'])->failures))->toBe([BrueckenSubjektquellen::NABE]);
});

/**
 * 6. Der Name selbst — die Vermittlung, kein System.
 *
 * Steht als eigener Test, weil die Tests oben über die Konstante sprechen und
 * deshalb auch mit `ai-brain` grün wären, sobald keine Quelle so heisst. Der
 * Schlüssel wandert in JSON-Antworten an Masken; ein Rückfall auf einen
 * Systemnamen soll hier laut werden und nicht erst im Manager.
 */
it('heisst nabe', function () {
    expect(BrueckenSubjektquellen::NABE)->toBe('nabe');
});
