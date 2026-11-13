<?php

use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Sources\BrueckenSubjektquelle;
use Peppermint\Tasks\Sources\BrueckenSubjektquellen;
use Peppermint\Tasks\Sources\SourceFailure;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;
use Peppermint\Tasks\Tests\Support\Person;

/*
 * Die fragende Seite (#9669): die Aufgaben eines ANDEREN Produkts zu einem
 * Datensatz, geholt über den Komm-Layer.
 *
 * Eine Quelle je antwortendem System, damit jedes seinen eigenen Chip bekommt.
 * Wer antwortet, sagt das Fähigkeits-Register in Brain — nicht eine Liste im
 * Produkt.
 */

/**
 * Eine angemeldete Person.
 *
 * Nötig, weil die Quellen erst die Wache fragen: Ohne Person gibt es
 * niemanden, dessen fremde Aufgaben gemeint wären, und keinen Grund, dafür
 * über die Systemgrenze zu gehen.
 */
function angemeldet(): void
{
    test()->actingAs(new Person(['id' => 1, 'name' => 'Bastian']));
}

/**
 * Tut so, als wäre die Brücke eingerichtet — und als sässe jemand davor.
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
    angemeldet();

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

    // Seit #9887 werden ZWEI Vorgänge gefragt — „was gehört zu diesem
    // Datensatz" und „meine Aufgaben dort". Angemeldet wird daraus trotzdem
    // eine Quelle je System: Das Register hält eine je Schlüssel, und zwei
    // Anmeldungen überschrieben einander lautlos.
    AiBrain::shouldReceive('providers')
        ->with(BrueckenSubjektquelle::CAPABILITY)
        ->andReturn([
            ['slug' => 'peppermint-crm', 'name' => 'Peppermint CRM', 'key' => 'crm', 'label' => 'CRM'],
            ['slug' => 'peppermint-manager', 'name' => 'Peppermint Manager', 'key' => 'manager', 'label' => 'Manager'],
        ]);

    AiBrain::shouldReceive('providers')
        ->with(BrueckenSubjektquelle::CAPABILITY_PERSON)
        ->andReturn([
            ['slug' => 'peppermint-crm', 'name' => 'Peppermint CRM', 'key' => 'crm', 'label' => 'CRM'],
            ['slug' => 'peppermint-manager', 'name' => 'Peppermint Manager', 'key' => 'manager', 'label' => 'Manager'],
        ]);

    $register = new TaskSourceRegistry;

    expect((new BrueckenSubjektquellen)->melden($register))->toBe(2)
        ->and(array_keys($register->all()))->toBe(['crm', 'manager']);
});

/**
 * Ein System, das NUR die Personen-Frage beantwortet, bekommt trotzdem eine
 * Quelle (#9887).
 *
 * Vorher lief der Anmelder allein über `tasks.subject.list`. Wer den nicht
 * anbot, stand in keiner Liste — und zwar lautlos: Es entstand nirgends ein
 * Fehler, das System war einfach nicht da.
 */
it('meldet auch ein System an, das nur die Personen-Frage beantwortet', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')
        ->with(BrueckenSubjektquelle::CAPABILITY)
        ->andReturn([
            ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
        ]);

    AiBrain::shouldReceive('providers')
        ->with(BrueckenSubjektquelle::CAPABILITY_PERSON)
        ->andReturn([
            ['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung'],
        ]);

    $register = new TaskSourceRegistry;

    expect((new BrueckenSubjektquellen)->melden($register))->toBe(2)
        // Die Vereinigung, und die Subjekt-Antwort zuerst — damit sich an
        // bestehenden Oberflächen nichts verschiebt.
        ->and(array_keys($register->all()))->toBe(['crm', 'verwaltung']);

    // Und jede trägt genau die Frage, die ihr System beantwortet.
    expect($register->all()['crm']->supportsSubjects())->toBeTrue()
        ->and($register->all()['crm']->supportsPerson())->toBeFalse()
        ->and($register->all()['verwaltung']->supportsSubjects())->toBeFalse()
        ->and($register->all()['verwaltung']->supportsPerson())->toBeTrue();
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
        'error' => SourceFailure::NOT_ALLOWED,
        'message' => 'Für „tasks.subject.list" ist dieses System nicht freigeschaltet.',
    ]);

    $quelle = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');

    // Das Register fängt das und gibt es als Angabe weiter. Eine leere Liste
    // hier liesse sich nicht von „dazu gehört nichts" unterscheiden.
    $register = new TaskSourceRegistry;
    $register->register($quelle);

    $antwort = $register->collectForSubject('auftrag', 7);

    // `complete()` statt des frueheren `isIncomplete()`: seit der
    // Zusammenlegung (#9809) antwortet `collectForSubject()` in derselben Form
    // wie `harvest()`, und zwei Namen fuer eine Frage pflegt irgendwann
    // niemand mehr beide.
    expect($antwort->complete())->toBeFalse()
        ->and($antwort->failures['crm']->reason)->toBe('not_allowed')
        // „Nicht freigeschaltet" ist nichts, was Warten behebt — und genau
        // deshalb steht der Grund typisiert in der Antwort: Die Maske darf
        // hier kein „neu laden" anbieten.
        ->and($antwort->failures['crm']->mightPassByItself())->toBeFalse();
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

/*
 * Die vier Tests hierunter halten das HEUTIGE Entpacken der Antwort fest
 * (#9809, 11.11.2026) — BEVOR die Handarbeit hier durch `RemoteResult` ersetzt
 * wird.
 *
 * Grund: Diese Datei beschreibt gepushte, fremde Arbeit. Ein Unterschied im
 * Entpacken ist ein Unterschied im Verhalten, und er soll an einem roten Test
 * sichtbar werden statt in einer Abnahme. Was nach der Umstellung anders ist,
 * wird hier mit Begründung umgeschrieben — nicht gelöscht.
 */

it('wertet ein ok, das nicht genau true ist, als Fehlschlag', function () {
    bitteEingerichtet();

    // Streng gelesen, und das ist die vorsichtige Richtung: Eine Antwort, die
    // nicht `true` sagt, ist eine kaputte Antwort. Sie als Erfolg zu lesen
    // ergäbe eine leere Liste, die sich als vollständige Auskunft ausgibt —
    // genau der stille Fehler, gegen den dieses Paket gebaut ist.
    AiBrain::shouldReceive('gateway')->andReturn(['ok' => 1, 'data' => [
        ['id' => 11, 'title' => 'Würde durchrutschen'],
    ]]);

    expect(fn () => (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))
        ->tasksForSubject('deal', 4312))
        ->toThrow(ForeignSourceFailed::class);
});

it('liefert eine leere Liste, wenn data gar keine Liste ist', function () {
    bitteEingerichtet();

    // Kein Wurf: Die Gegenstelle hat geantwortet, nur mit etwas Unbrauchbarem.
    // Das ist kein Ausfall, den eine Maske als „nicht erreichbar" zeigen darf.
    AiBrain::shouldReceive('gateway')->andReturn(['ok' => true, 'data' => 'nichts']);

    expect((new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))
        ->tasksForSubject('deal', 4312))->toBe([]);
});

it('packt einen Umschlag um die Liste aus, statt ins Leere zu laufen', function () {
    bitteEingerichtet();

    // Umgeschrieben am 11.11.2026 (#9809), und das neue Verhalten ist das
    // richtige: Vorher fiel in diesem Fall ALLES weg — `count` ist keine
    // Zeile, und die innere Liste hat kein `id`. Ergebnis war eine leere
    // Liste, wo drüben eine Aufgabe liegt, und zwar eine leere Liste, die sich
    // als vollständige Auskunft ausgibt.
    //
    // Der Vertrag drüben ist die blanke Liste, daran ändert das nichts. Aber
    // die strenge Lesart bestraft den Leser für den Fehler des Schreibers, und
    // die Aufgaben-Werkzeuge HABEN den Umschlag geschickt — auf der
    // Personen-Seite ist er gemessen und dort schon entpackt worden.
    AiBrain::shouldReceive('gateway')->andReturn(['ok' => true, 'data' => [
        'count' => 1,
        'data' => [['id' => 11, 'title' => 'Im Umschlag']],
    ]]);

    $aufgaben = (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))
        ->tasksForSubject('deal', 4312);

    expect($aufgaben)->toHaveCount(1)
        ->and($aufgaben[0]->title)->toBe('Im Umschlag');
});

it('nimmt den Text der Gegenstelle in den Wurf, nicht nur den allgemeinen Satz', function () {
    bitteEingerichtet();

    // Umgeschrieben am 11.11.2026 (#9809). Vorher wurde `message` hier gar
    // nicht gelesen, und im Wurf stand der allgemeine Satz von
    // `ForeignSourceFailed::during()` — er sagt, WO es gescheitert ist, und
    // verschweigt, was drüben los war, obwohl die Gegenstelle es mitgeschickt
    // hatte.
    //
    // Der Text ist das, was ein Mensch am Ende liest. „Zeitüberschreitung nach
    // 30s" sagt jemandem, was zu tun ist; „der Vorgang ist im System crm
    // gescheitert" sagt es nicht. Der GRUND daneben bleibt typisiert — der
    // Text ist für den Menschen, der Grund für die Maske.
    AiBrain::shouldReceive('gateway')->andReturn([
        'ok' => false,
        'error' => SourceFailure::UNREACHABLE,
        'message' => 'Zeitüberschreitung nach 30s',
    ]);

    try {
        (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))->tasksForSubject('deal', 4312);
        $wurf = null;
    } catch (ForeignSourceFailed $e) {
        $wurf = $e;
    }

    expect($wurf?->getMessage())->toContain('Zeitüberschreitung nach 30s')
        ->and($wurf?->getMessage())->toContain('CRM')
        ->and($wurf?->errorClass)->toBe('unreachable');
});

it('wirft auch dann mit einem Satz, wenn die Gegenstelle keinen mitschickt', function () {
    bitteEingerichtet();

    // „nicht erreichbar: " ohne Text ist schlimmer als eine ehrliche
    // Fehlanzeige — und `RemoteResult` fällt für `message` auf `error` zurück,
    // sodass hier wenigstens die Fehlerklasse steht. Fehlt auch die, sagt der
    // Satz das ausdrücklich.
    AiBrain::shouldReceive('gateway')->andReturn(['ok' => false]);

    try {
        (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))->tasksForSubject('deal', 4312);
        $satz = null;
    } catch (ForeignSourceFailed $e) {
        $satz = $e->getMessage();
    }

    expect($satz)->toContain('ohne Angabe der Gegenstelle');
});

/**
 * Beide Fragen an EINER Quelle (#9887).
 *
 * Hier stand bis zum 13.11.2026 das Gegenteil: „beantwortet die PERSONEN-Frage
 * nicht und bekommt deshalb keinen Chip". Die Begründung dafür war, der Weg
 * „meine Aufgaben dort" laufe schon Produkt → Brain → Produkt — und genau das
 * wurde am 13.11.2026 nachgemessen und traf nicht zu: Die Nabe SAMMELT die
 * Aufgaben aller Produkte, aber kein Produkt bekommt den Sammelbestand. Die
 * Personen-Frage hatte also gar keine Antwort.
 *
 * Der alte Satz war trotzdem nicht falsch, sondern nur zu eng: Ein Chip, der
 * IMMER leer ist, liest sich als „von mir liegt da nichts" und ist damit eine
 * Behauptung statt einer Abwesenheit. Deshalb hängt es jetzt an einer
 * gemessenen Angabe — wer `tasks.list` anbietet, bekommt den Chip, und nur der.
 */
it('beantwortet beide Fragen, wenn das System beide Vorgänge anbietet', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
    ]);

    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('alt'));
    (new BrueckenSubjektquellen)->melden($register);

    expect(array_keys($register->forPersons()))->toBe(['alt', 'crm'])
        ->and(array_keys($register->forSubjects()))->toBe(['crm'])
        // Der Kern der Entscheidung: EIN Eintrag je System, nicht zwei.
        ->and(array_keys($register->all()))->toBe(['alt', 'crm']);
});

/**
 * Eine Quelle, deren System `tasks.list` NICHT anbietet, wird nicht gefragt.
 *
 * Die Frage ist dieselbe geblieben wie vor #9887, nur hängt die Antwort jetzt
 * an einer Messung statt an der Klasse: Ein `gateway`-Aufruf wäre ein Gang über
 * die Systemgrenze für eine Frage, die dieses System nicht hält.
 */
it('fragt eine Quelle ohne Personen-Antwort in collect() gar nicht', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')
        ->with(BrueckenSubjektquelle::CAPABILITY)
        ->andReturn([
            ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
        ]);

    // Das CRM steht NICHT unter den Anbietern der Personen-Frage.
    AiBrain::shouldReceive('providers')
        ->with(BrueckenSubjektquelle::CAPABILITY_PERSON)
        ->andReturn([]);

    AiBrain::shouldReceive('gateway')->never();

    $register = new TaskSourceRegistry;
    (new BrueckenSubjektquellen)->melden($register);

    expect($register->collect(userId: 1))->toBe([]);
});

/**
 * Und die Gegenprobe: Wer sie anbietet, WIRD gefragt — mit der angemeldeten
 * Person und mit den beiden Argumenten, ohne die die Antwort bei 50 aufhört
 * oder zu 93 Prozent aus Erledigtem besteht (#8471).
 */
it('holt die eigenen Aufgaben eines Systems, das die Personen-Frage anbietet', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung'],
    ]);

    AiBrain::shouldReceive('gateway')
        ->once()
        ->with(BrueckenSubjektquelle::CAPABILITY_PERSON, [
            'limit' => BrueckenSubjektquelle::ZEILEN,
            'active_only' => true,
        ], 'peppermint-verwaltung')
        ->andReturn(['ok' => true, 'data' => [
            ['id' => 40, 'title' => 'Ticket prüfen', 'status' => 'open', 'url' => 'https://vw/tickets/40'],
        ]]);

    $register = new TaskSourceRegistry;
    (new BrueckenSubjektquellen)->melden($register);

    $aufgaben = $register->collect(userId: 1);

    expect($aufgaben)->toHaveCount(1)
        ->and($aufgaben[0]->id)->toBe(40)
        ->and($aufgaben[0]->sourceKey)->toBe('verwaltung')
        // Unübersetzt: „open" heisst dort „open".
        ->and($aufgaben[0]->status)->toBe('open')
        ->and($aufgaben[0]->url)->toBe('https://vw/tickets/40');
});

/**
 * Nur die eigenen — fail-closed, und zwar HIER.
 *
 * Bastian am 11.11.2026: „Natürlich darf ich nur meine Aufgaben sehen." Ein
 * Aufruf, dessen Identität unterwegs verlorengeht, macht die Antwort WEITER
 * statt enger; das war Bug #657 im Kalender, und in einer Aufgabenliste hiesse
 * es, jemandem die Aufgaben eines anderen zu zeigen.
 */
it('holt die Aufgaben einer anderen Person gar nicht erst', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung'],
    ]);

    AiBrain::shouldReceive('gateway')->never();

    // Angemeldet ist 1 (siehe `angemeldet()`), gefragt wird nach 2.
    expect((new BrueckenSubjektquelle('peppermint-verwaltung', 'verwaltung', 'Verwaltung', null, personen: true))
        ->tasks(2))->toBe([]);
});

/**
 * Ein Ausfall bei der Personen-Frage ist kein leeres Ergebnis.
 *
 * Dieselbe Trennung wie auf der Datensatz-Seite: Die Fehlerklasse des
 * Komm-Layers reist mit, weil „nicht freigeschaltet" und „antwortet nicht" zu
 * verschiedenen nächsten Schritten führen.
 */
it('reicht die Fehlerklasse auch bei der Personen-Frage durch', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung'],
    ]);

    AiBrain::shouldReceive('gateway')->andReturn([
        'ok' => false, 'error' => SourceFailure::NOT_ALLOWED, 'message' => 'Keine Freigabe',
    ]);

    $quelle = new BrueckenSubjektquelle('peppermint-verwaltung', 'verwaltung', 'Verwaltung', null, personen: true);

    try {
        $quelle->tasks(1);
        $klasse = null;
        $satz = null;
    } catch (ForeignSourceFailed $e) {
        $klasse = $e->errorClass;
        $satz = $e->getMessage();
    }

    expect($klasse)->toBe(SourceFailure::NOT_ALLOWED)
        ->and($satz)->toContain('Keine Freigabe');
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

it('fragt ohne angemeldete Person gar nicht erst über die Leitung', function () {
    Bruecke::vergessen();

    $tokens = new OAuthTokenProvider([]);
    app()->instance(AiBrainManager::class, new AiBrainManager(
        config: [],
        tokens: $tokens,
        publisher: new EventPublisher([], $tokens, 'tasks-test'),
    ));

    // KEIN `angemeldet()`. Ein Konsolenbefehl, eine Warteschlangen-Aufgabe und
    // ein anonymer Zugriff sollen keine Anbieter erfragen — und zwar nicht aus
    // Sparsamkeit: Ohne Person gibt es niemanden, dessen fremde Aufgaben
    // gemeint wären. Dieselbe Reihenfolge wie bei BrainCalendarSources.
    AiBrain::shouldReceive('providers')->never();

    $register = new TaskSourceRegistry;

    expect((new BrueckenSubjektquellen)->melden($register))->toBe(0)
        ->and($register->all())->toBe([]);
});

it('nimmt die Farbe nach dem KURZEN Schlüssel, wie der Kalender', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-manager', 'key' => 'manager', 'label' => 'Manager'],
    ]);

    $register = new TaskSourceRegistry;

    // Nach dem kurzen Schlüssel und nicht nach dem Slug, weil es diese
    // Zuordnung schon gibt: `config('calendar.source_colours')` ist genau so
    // aufgebaut. Ein Produkt gibt seine VORHANDENE mit, statt eine zweite zu
    // pflegen — dasselbe System soll im Terminraster und in der Aufgabenliste
    // dieselbe Farbe haben.
    (new BrueckenSubjektquellen(farben: ['manager' => '#f59e0b']))->melden($register);

    expect($register->all()['manager']->colour())->toBe('#f59e0b');
});

it('meldet keine Farbe, wenn es keine Zuordnung gibt', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('providers')->andReturn([
        ['slug' => 'peppermint-manager', 'key' => 'manager', 'label' => 'Manager'],
    ]);

    $register = new TaskSourceRegistry;
    (new BrueckenSubjektquellen)->melden($register);

    // `null` heisst „die Anwendung entscheidet" — besser als eine erfundene
    // Farbe, die neben einer bestehenden steht.
    expect($register->all()['manager']->colour())->toBeNull();
});

it('reicht die übrigen Felder in extra durch, statt sie wegzuwerfen', function () {
    bitteEingerichtet();

    AiBrain::shouldReceive('gateway')->andReturn(['ok' => true, 'data' => [[
        'id' => 11,
        'title' => 'Rechnung schreiben',
        'status' => 'open',
        'status_label' => 'Offen',
        'assigned_user' => ['id' => 3, 'name' => 'Bastian'],
        'ticket_number' => 'T-42',
    ]]]);

    $aufgabe = (new BrueckenSubjektquelle('peppermint-verwaltung', 'verwaltung', 'Verwaltung'))
        ->tasksForSubject('auftrag', 7)[0];

    // Was das Haus des Nachbarn verlässt, entscheidet der NACHBAR in seinem
    // antwortenden Werkzeug. Eine Auswahl hier wäre eine zweite Filterung an
    // der falschen Stelle — und jedes neue Anzeigefeld bräuchte eine
    // Paketfreigabe.
    expect($aufgabe->extra)->toBe([
        'status_label' => 'Offen',
        'assigned_user' => ['id' => 3, 'name' => 'Bastian'],
        'ticket_number' => 'T-42',
    ]);

    // Die festen Felder bleiben, wo sie hingehören — nicht doppelt.
    expect($aufgabe->status)->toBe('open')
        ->and($aufgabe->extra)->not->toHaveKey('status')
        ->and($aufgabe->extra)->not->toHaveKey('title');
});
