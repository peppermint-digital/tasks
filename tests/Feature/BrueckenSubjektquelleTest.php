<?php

use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\Tasks\Sources\BrueckenSubjektquelle;
use Peppermint\Tasks\Sources\BrueckenSubjektquellen;
use Peppermint\Tasks\Sources\SubjectSourceFailure;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;

/*
 * Die fragende Seite (#9669): die Aufgaben eines ANDEREN Produkts zu einem
 * Datensatz, geholt über den Komm-Layer.
 *
 * Eine Quelle je antwortendem System, damit jedes seinen eigenen Chip bekommt.
 * Wer antwortet, sagt das Fähigkeits-Register in Brain — nicht eine Liste im
 * Produkt.
 */

/**
 * Tut so, als wäre die Brücke eingerichtet.
 *
 * `Bruecke::eingerichtet()` fragt nicht nach der Klasse, sondern danach, ob
 * sie sich AUFLÖSEN lässt — ein `class_exists` sagte beim Hub „ja", und der
 * erste Aufruf wirft dann `Unresolvable dependency … array $config`.
 *
 * Deshalb eine ECHTE Instanz mit leerer Konfiguration, keine Attrappe: Mockery
 * kann dieselbe Mock-Klasse nicht zweimal im Prozess erzeugen
 * („Cannot redeclare Mockery_…_AiBrainManager::mockery_init()"), und die Wege,
 * um die es hier geht, laufen ohnehin über die FASSADE — die wird je Test
 * eigens gestellt.
 */
function bitteEingerichtet(): void
{
    Bruecke::vergessen();

    $tokens = new OAuthTokenProvider([]);

    app()->instance(AiBrainManager::class, new AiBrainManager(
        config: [],
        tokens: $tokens,
        publisher: new EventPublisher([], $tokens, 'tasks-test'),
    ));
}

afterEach(function () {
    // Die Brücke merkt ihre Antwort für die Dauer eines Aufrufs. Ohne das
    // Vergessen trüge der nächste Test die Antwort des vorigen — und wäre
    // grün, ohne seinen eigenen Fall zu prüfen.
    Bruecke::vergessen();
});

it('meldet für jedes antwortende System eine Quelle an', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')
        ->with(BrueckenSubjektquelle::CAPABILITY)
        ->andReturn([
            ['slug' => 'peppermint-crm', 'name' => 'Peppermint CRM', 'key' => 'crm', 'label' => 'CRM'],
            ['slug' => 'peppermint-manager', 'name' => 'Peppermint Manager', 'key' => 'manager', 'label' => 'Manager'],
        ]);

    $register = new TaskSourceRegistry;

    expect((new BrueckenSubjektquellen)->melden($register))->toBe(2)
        ->and(array_keys($register->all()))->toBe(['crm', 'manager']);
});

it('nimmt den KURZEN Schlüssel der Brücke, nicht den Slug', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
    ]);

    $register = new TaskSourceRegistry;
    (new BrueckenSubjektquellen)->melden($register);

    // Der Schlüssel steckt in gespeicherten Filtern der Nutzer. Würde das
    // Paket ihn selbst ableiten, hiesse dasselbe System in zwei Oberflächen
    // irgendwann verschieden — und ein gespeicherter Filter wäre stumm falsch.
    expect($register->has('crm'))->toBeTrue()
        ->and($register->has('peppermint-crm'))->toBeFalse();
});

it('meldet nichts an, wenn die Brücke nicht eingerichtet ist', function () {
    Bruecke::vergessen();

    $register = new TaskSourceRegistry;

    // Kein Fehlerzustand: AI Brain selbst hat das Paket, ohne die Brücke
    // einzurichten — es ist die Nabe.
    expect((new BrueckenSubjektquellen)->melden($register))->toBe(0);
});

it('lässt eine Anbieterzeile ohne Slug draussen, statt die Liste zu verwerfen', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['name' => 'Kaputt, ohne Slug'],
        ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
    ]);

    $register = new TaskSourceRegistry;

    expect((new BrueckenSubjektquellen)->melden($register))->toBe(1)
        ->and(array_keys($register->all()))->toBe(['crm']);
});

it('holt die Aufgaben zu einem Datensatz und nennt das Zielsystem dabei', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('gateway')
        ->once()
        ->with(
            BrueckenSubjektquelle::CAPABILITY,
            ['subject_type' => 'auftrag', 'subject_id' => '7'],
            'peppermint-verwaltung',
        )
        ->andReturn(['ok' => true, 'data' => [
            ['id' => 11, 'title' => 'Rechnung schreiben', 'status' => 'open', 'url' => 'https://vw.test/tickets/11'],
        ]]);

    $quelle = new BrueckenSubjektquelle('peppermint-verwaltung', 'verwaltung', 'Verwaltung');

    $aufgaben = $quelle->tasksForSubject('auftrag', 7);

    expect($aufgaben)->toHaveCount(1)
        ->and($aufgaben[0]->title)->toBe('Rechnung schreiben')
        ->and($aufgaben[0]->sourceKey)->toBe('verwaltung')
        // Die Adresse kommt von DRÜBEN. Eine geratene führt auf eine 404 und
        // sieht dabei aus wie ein Weg, der funktioniert.
        ->and($aufgaben[0]->url)->toBe('https://vw.test/tickets/11');
});

it('übersetzt Zustand und Priorität NICHT', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('gateway')->andReturn(['ok' => true, 'data' => [
        ['id' => 11, 'title' => 'X', 'status' => 'resolved', 'priority' => 'urgent'],
    ]]);

    $aufgaben = (new BrueckenSubjektquelle('peppermint-verwaltung', 'verwaltung', 'Verwaltung'))
        ->tasksForSubject('auftrag', 7);

    // Die Wörter der drei Systeme überschneiden sich in genau einem Status und
    // in drei von fünf Prioritäten. Eine Zuordnung hier behauptete eine
    // Gleichheit, die es nicht gibt — sie reisen mit ihrer Herkunft.
    expect($aufgaben[0]->status)->toBe('resolved')
        ->and($aufgaben[0]->priority)->toBe('urgent');
});

it('reicht die Fehlerklasse des Komm-Layers durch, statt eine leere Liste zu liefern', function () {
    bitteEingerichtet();

    // Das System ANTWORTET (es steht unter den Anbietern) — es lehnt nur
    // diesen Aufruf ab. Genau dieser Unterschied ist der Gegenstand des
    // Tests: „nicht freigeschaltet" ist kein „gibt es nicht".
    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
    ]);

    AiBrain::shouldReceive('gateway')->andReturn([
        'ok' => false,
        'error' => SubjectSourceFailure::NOT_ALLOWED,
        'message' => 'Für „tasks.subject.list" ist dieses System nicht freigeschaltet.',
    ]);

    $quelle = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');

    // Das Register fängt das und gibt es als Angabe weiter. Eine leere Liste
    // hier liesse sich nicht von „dazu gehört nichts" unterscheiden.
    $register = new TaskSourceRegistry;
    $register->register($quelle);

    $antwort = $register->collectForSubject('auftrag', 7);

    expect($antwort->isIncomplete())->toBeTrue()
        ->and($antwort->failures['crm']->errorClass)->toBe('not_allowed');
});

it('lässt eine Zeile ohne id draussen, statt eine Aufgabe ohne Kennung zu bauen', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('gateway')->andReturn(['ok' => true, 'data' => [
        ['title' => 'Ohne Kennung'],
        ['id' => 11, 'title' => 'Mit Kennung'],
        'kaputt',
    ]]);

    $aufgaben = (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))
        ->tasksForSubject('deal', 4312);

    // Eine Aufgabe ohne Kennung ist nicht anklickbar und nicht
    // wiederfindbar — sie wäre eine Zeile, die aussieht wie ein Verweis.
    expect($aufgaben)->toHaveCount(1)
        ->and($aufgaben[0]->title)->toBe('Mit Kennung');
});

it('beantwortet die PERSONEN-Frage nicht und bekommt deshalb keinen Chip', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
    ]);

    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('alt'));
    (new BrueckenSubjektquellen)->melden($register);

    // Ein Chip, der immer leer ist, ist keine Kleinigkeit: Er liest sich als
    // „von mir liegt da nichts", und das ist eine Behauptung, keine Abwesenheit.
    expect(array_keys($register->forPersons()))->toBe(['alt'])
        ->and(array_keys($register->forSubjects()))->toBe(['crm']);
});

it('fragt eine Quelle ohne Personen-Antwort in collect() gar nicht', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
    ]);
    // Ein `gateway`-Aufruf waere hier ein Gang über die Systemgrenze für eine
    // Frage, die diese Quelle nicht hält.
    AiBrain::shouldReceive('gateway')->never();

    $register = new TaskSourceRegistry;
    (new BrueckenSubjektquellen)->melden($register);

    expect($register->collect(userId: 1))->toBe([]);
});

it('verschwindet von selbst, wenn das System die Fähigkeit verliert', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-manager', 'key' => 'manager', 'label' => 'Manager'],
    ]);

    $quelle = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');

    // Gemessen, nicht konfiguriert: Das CRM steht nicht mehr unter den
    // Anbietern, also ist die Quelle nicht verfügbar — ohne dass jemand einen
    // Schalter umlegen muss.
    expect($quelle->isAvailable())->toBeFalse();

    // Gegenprobe: Der Manager steht drin.
    expect((new BrueckenSubjektquelle('peppermint-manager', 'manager', 'Manager'))->isAvailable())->toBeTrue();
});

/*
 * Der Weg durch die Konfiguration (#9669).
 *
 * Ein Produkt nennt seine Quellen an EINER Stelle: `config('tasks.sources')`.
 * Seit es Quellen gibt, deren Anzahl erst zur Laufzeit feststeht, darf dort
 * auch ein Anmelder stehen — sonst bräuchte jedes Produkt eine dünne
 * Unterklasse je Nachbarsystem.
 */

it('nimmt einen Anmelder in config(tasks.sources) an und meldet dessen Quellen', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
        ['slug' => 'peppermint-manager', 'key' => 'manager', 'label' => 'Manager'],
    ]);

    config()->set('tasks.sources', [BrueckenSubjektquellen::class]);
    app()->forgetInstance(TaskSourceRegistry::class);

    // Zwei Quellen aus EINEM Konfigurationseintrag. Ohne diesen Weg stünden
    // hier zwei Klassennamen, und bei drei Produkten mit je zwei Nachbarn
    // wären es sechs Klassen, die dasselbe tun.
    expect(array_keys(app(TaskSourceRegistry::class)->all()))->toBe(['crm', 'manager']);
});

it('nimmt weiterhin eine einfache Quelle in der Konfiguration an', function () {
    config()->set('tasks.sources', [ErfundeneQuelle::class]);
    app()->forgetInstance(TaskSourceRegistry::class);

    // Gegenprobe: Der neue Weg darf den alten nicht verdrängen — jede
    // bestehende Quelle in jedem Produkt steht so in der Konfiguration.
    expect(array_keys(app(TaskSourceRegistry::class)->all()))->toBe(['fremd']);
});

it('sagt bei einem Eintrag, der keines von beidem ist, laut Bescheid', function () {
    config()->set('tasks.sources', [stdClass::class]);
    app()->forgetInstance(TaskSourceRegistry::class);

    // Ein Tippfehler in `config/tasks.php` taucht sonst viel später als
    // fehlende Methode an etwas auf, das dort niemand erwartet hat.
    expect(fn () => app(TaskSourceRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'does not extend');
});
