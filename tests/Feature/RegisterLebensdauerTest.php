<?php

use Illuminate\Support\Facades\Auth;
use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\Tasks\Sources\BrueckenSubjektquelle;
use Peppermint\Tasks\Sources\BrueckenSubjektquellen;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\Person;

/*
 * Die LEBENSDAUER des Quellenregisters (Bug #1479).
 *
 * Nicht zu verwechseln mit `AnbieterMerkerTest` (#1469): Dort geht es um die
 * ZAHL der Netzaufrufe INNERHALB einer Anfrage. Hier geht es darum, wie lange
 * das Register ueberhaupt lebt — zwei Fragen, die sich leicht vermischen.
 *
 * ## Was hier festgenagelt wird
 *
 * Das Register war bis zum 17.11.2026 `singleton()` gebunden, und sein INHALT
 * haengt am angemeldeten Nutzer: `BrueckenSubjektquellen::melden()` fragt erst
 * die Wache und meldet ohne Person null Quellen. Unter PHP-FPM war das
 * folgenlos, weil der Container je Anfrage neu entsteht. Unter Octane lebt ein
 * Singleton so lange wie der Arbeiter — Stunden:
 *
 * | Reihenfolge im selben Arbeiter | Folge |
 * |---|---|
 * | erst ohne Anmeldung, dann mit | das Register bleibt LEER, fuer alle folgenden Anfragen |
 * | erst Person A, dann Person B   | B sieht die Liste, die fuer A gebaut wurde |
 *
 * Beides lautlos — kein Fehler, keine Ausnahme, nur eine Liste, die kuerzer
 * ist als sie sein sollte. Fuer eine Maske heisst das „dieses System gibt es
 * nicht" statt „es antwortet gerade nicht".
 *
 * ## Es ist ein RICHTIGKEITSfehler und kein Vertraulichkeitsfehler
 *
 * Das steht hier, damit es niemand groesser liest als es ist: Eine Quelle
 * traegt Slug, Beschriftung und Farbe. Die Aufgaben werden weiterhin je
 * `userId` geholt — Person B bekaeme also die FALSCHE SYSTEMLISTE, nicht die
 * Aufgaben von Person A.
 *
 * ## Warum hier kein Octane laeuft, und das trotzdem der echte Haken ist
 *
 * `app()->forgetScopedInstances()` ist genau der Griff, den Octane zwischen
 * zwei Anfragen tut — und derselbe, den der Warteschlangen-Arbeiter zwischen
 * zwei Aufgaben macht (`QueueServiceProvider::registerWorker()`, die
 * `$resetScope`-Closure, die dem `Worker` mitgegeben wird; nachgesehen am
 * 17.11.2026 im Baum). Es gibt im Framework genau diese zwei Aufrufer. Ein
 * Test braucht also kein Octane, um die Anfragegrenze nachzustellen — er
 * braucht den Haken, an dem sie haengt.
 *
 * ## Und warum das heute in keinem Produkt auftritt
 *
 * ai-brain faehrt Octane, meldet `BrueckenSubjektquellen` aber nicht an;
 * Manager, CRM und Verwaltung melden sie an und fahren kein Octane. Die beiden
 * Bedingungen treffen sich nirgends. Es war nicht sicher GEBAUT, sondern
 * sicher AUFGESTELLT — und das haelt nur, solange die Aufstellung gleich
 * bleibt. Diese Datei ist der Grund, warum das beim naechsten Umbau auffaellt.
 */

/**
 * Eine eingerichtete Bruecke, und auf Wunsch jemand davor.
 *
 * Wortgleich zu `bitteEingerichtet()` in `BrueckenSubjektquelleTest` und
 * `merkerEingerichtet()` in `AnbieterMerkerTest` — und aus demselben Grund
 * eine ECHTE Instanz statt einer Attrappe: `Bruecke::eingerichtet()` fragt, ob
 * sich der Manager AUFLOESEN laesst, und Mockery kann dieselbe Mock-Klasse
 * nicht zweimal im Prozess erzeugen.
 *
 * Angemeldet wird hier ausdruecklich NICHT: Welcher Test wen anmeldet, ist in
 * dieser Datei die ganze Frage.
 */
function lebensdauerEingerichtet(): void
{
    Bruecke::vergessen();

    $tokens = new OAuthTokenProvider([]);

    app()->instance(AiBrainManager::class, new AiBrainManager(
        config: [],
        tokens: $tokens,
        publisher: new EventPublisher([], $tokens, 'tasks-test'),
    ));
}

/** Das Register so verdrahten, wie ein Produkt es tut: ueber die Konfiguration. */
function lebensdauerQuellenAngemeldet(): void
{
    config()->set('tasks.sources', [BrueckenSubjektquellen::class]);

    // Falls in diesem Test schon einmal aufgeloest: Die Konfiguration wirkt
    // nur auf eine Bindung, die noch nicht aufgeloest ist.
    app()->forgetInstance(TaskSourceRegistry::class);
}

/**
 * Zaehlt jeden Gang ueber die Leitung mit und antwortet je ANGEMELDETER PERSON
 * verschieden.
 *
 * Die Unterscheidung nach `Auth::id()` ist nicht erfunden, sondern genau das,
 * was das Faehigkeits-Register in Brain tut: Es nennt nur Systeme, fuer die
 * DIESE Person freigeschaltet ist. Ohne diese Abhaengigkeit waere der ganze
 * Fehler nicht moeglich — und ein Test, der sie nicht nachstellt, koennte den
 * Personenwechsel gar nicht sehen.
 *
 * Gezaehlt wird mit einem eigenen Zaehler und nicht mit `->once()`: Eine
 * Mockery-Erwartung faellt erst beim Aufraeumen um und sagt dann „received 2
 * times" — die ZAHL stuende damit in der Fehlermeldung statt im Test. Dieselbe
 * Begruendung wie bei `merkerZaehler()`.
 */
function lebensdauerZaehler(?int &$aufrufe): void
{
    $aufrufe = 0;

    AiBrain::shouldReceive('anbieterMitGrund')->andReturnUsing(anbieterAntwortend(function () use (&$aufrufe): array {
        $aufrufe++;

        return match (Auth::id()) {
            1 => [
                ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
                ['slug' => 'peppermint-manager', 'key' => 'manager', 'label' => 'Manager'],
            ],
            2 => [
                ['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung'],
            ],
            // Ohne Person wird gar nicht erst gefragt — kaeme es hier doch an,
            // waere die Wache der Fehler und nicht die Bindung.
            default => [],
        };
    }));
}

/** Person A. */
function lebensdauerPersonA(): Person
{
    return new Person(['id' => 1, 'name' => 'Bastian']);
}

/** Person B — mit einer ANDEREN Freigabeliste, siehe `lebensdauerZaehler()`. */
function lebensdauerPersonB(): Person
{
    return new Person(['id' => 2, 'name' => 'Niklas']);
}

afterEach(function () {
    // Die Bruecke merkt ihre Antwort statisch. Ohne das Vergessen truege der
    // naechste Test die Antwort des vorigen.
    Bruecke::vergessen();
});

/**
 * 1. Die alte Falle: erst ohne Anmeldung, dann mit.
 *
 * Mit `singleton()` bleibt die zweite Aufloesung LEER — das ist der Fehler.
 * Das Register traegt dann fuer die restliche Lebenszeit des Arbeiters die
 * Antwort einer Anfrage, in der niemand angemeldet war.
 */
it('traegt nach einer Anfrage OHNE Anmeldung nicht die leere Liste weiter', function () {
    lebensdauerEingerichtet();
    lebensdauerQuellenAngemeldet();
    lebensdauerZaehler($aufrufe);

    // --- Anfrage 1: niemand angemeldet. Kein `actingAs`, und das ist hier
    // eine echte Aussage: Die `TestCase` des Pakets meldet NIEMANDEN an, es
    // gibt kein globales `beforeEach`, das eine Person setzt (Wiki #646 —
    // steht die Vorbedingung im globalen Aufbau, ist „ich setze sie hier
    // nicht" keine Aussage und der Test muss sie WEGNEHMEN). Gegengeprueft am
    // 17.11.2026 an `tests/TestCase.php` und `tests/Pest.php`.
    expect(app(TaskSourceRegistry::class)->all())->toBe([]);

    // Und zwar leer, weil die Wache vor der Leitung steht, nicht weil die
    // Gegenseite nichts gemeldet hat. Ohne diese Zeile waeren die beiden
    // Gruende nicht zu unterscheiden.
    expect($aufrufe)->toBe(0, 'Ohne Person darf gar nicht gefragt werden.');

    // --- Anfrageende. Genau der Griff, den Octane hier tut.
    app()->forgetScopedInstances();

    // --- Anfrage 2: jetzt mit Person.
    test()->actingAs(lebensdauerPersonA());

    // Das Entscheidende. Mit `singleton()` stuende hier `[]`, und zwar ohne
    // jede Fehlermeldung: Die fremden Systeme waeren fuer diesen Arbeiter
    // verschwunden.
    expect(array_keys(app(TaskSourceRegistry::class)->all()))->toBe(['crm', 'manager'])
        ->and($aufrufe)->toBe(2, 'Ein Aufruf je Vorgang, in der zweiten Anfrage.');
});

/**
 * 2. Die andere Richtung: erst Person A, dann Person B.
 *
 * Zwei Richtungen, zwei Tests — und zwar nicht aus Ordnungsliebe: Der erste
 * Test oben wuerde auch bestehen, wenn das Register NUR bei der leeren Liste
 * neu gebaut wuerde. Erst dieser hier sagt, dass es bei jeder Anfrage neu
 * gebaut wird, auch wenn in der vorigen schon etwas drinstand.
 */
it('baut die Liste nach einem Personenwechsel neu, statt die der vorigen Person zu zeigen', function () {
    lebensdauerEingerichtet();
    lebensdauerQuellenAngemeldet();
    lebensdauerZaehler($aufrufe);

    // --- Anfrage 1: Person A. Sie ist fuer CRM und Manager freigeschaltet.
    test()->actingAs(lebensdauerPersonA());

    expect(array_keys(app(TaskSourceRegistry::class)->all()))->toBe(['crm', 'manager']);

    // --- Anfrageende, dann Anfrage 2: Person B. Sie ist fuer die Verwaltung
    // freigeschaltet und fuer die beiden anderen nicht.
    app()->forgetScopedInstances();
    test()->actingAs(lebensdauerPersonB());

    // Mit `singleton()` stuende hier ['crm', 'manager'] — die Liste, die fuer
    // A gebaut wurde. B saehe zwei Systeme, die sie nicht aufrufen darf, und
    // die Verwaltung, die sie aufrufen darf, saehe sie nicht.
    expect(array_keys(app(TaskSourceRegistry::class)->all()))->toBe(['verwaltung']);
});

/**
 * 3. Die Kosten, gezaehlt — nicht behauptet.
 *
 * `scoped()` heisst: Der Anmelder fragt je ANFRAGE neu ueber die Leitung. Das
 * ist der Preis des Fixes, und er gehoert gemessen, weil `singleton()` genau
 * mit dieser Ersparnis begruendet war („Wer keine Quellen braucht, zahlt
 * nichts").
 *
 * Festgenagelt wird: EIN Aufruf je Vorgang und Anfrage — nicht je Aufloesung
 * des Registers und nicht je Quelle. Zusammen mit dem Merker aus #1469 bleibt
 * ein ganzer Seitenaufbau damit bei zwei Aufrufen, wie vorher.
 */
it('kostet je Anfrage genau einen Netzaufruf je Vorgang, egal wie oft das Register gefragt wird', function () {
    lebensdauerEingerichtet();
    lebensdauerQuellenAngemeldet();
    lebensdauerZaehler($aufrufe);

    test()->actingAs(lebensdauerPersonA());

    // --- Anfrage 1. Dreimal aufgeloest, plus beide Listen, die eine Maske
    // seit #9887 beide braucht und die `isAvailable()` ueber JEDE Quelle
    // laufen lassen.
    $erstes = app(TaskSourceRegistry::class);

    expect(app(TaskSourceRegistry::class))->toBe($erstes, 'Innerhalb einer Anfrage dasselbe Register.')
        ->and(app(TaskSourceRegistry::class))->toBe($erstes);

    expect(array_keys($erstes->forPersons()))->toBe(['crm', 'manager'])
        ->and(array_keys($erstes->forSubjects()))->toBe(['crm', 'manager']);

    expect($aufrufe)->toBe(2, 'Zwei Vorgaenge, zwei Aufrufe — und nur die.');

    // --- Anfrageende. Jetzt, und nur jetzt, wird wieder gezahlt.
    app()->forgetScopedInstances();

    $zweites = app(TaskSourceRegistry::class);
    $zweites->forPersons();
    $zweites->forSubjects();

    // Ein ANDERES Register und genau zwei weitere Aufrufe. Mit `singleton()`
    // stuende hier dasselbe Register und 2 — das waere billiger und falsch.
    expect($zweites)->not->toBe($erstes)
        ->and($aufrufe)->toBe(4, 'Je Anfrage zwei, nicht je Aufloesung zwei.');
});

/**
 * 4. Dasselbe fuer den zweiten langlebigen Prozess: den Warteschlangen-Arbeiter.
 *
 * Er ruft zwischen zwei Aufgaben denselben Griff. Der Unterschied zur Anfrage
 * ist, dass eine Aufgabe regulaer OHNE angemeldete Person laeuft — der Fall
 * aus Test 1 ist hier also der Normalfall und nicht die Ausnahme. Eine Aufgabe
 * darf deshalb nicht hinterlassen, was die naechste liest.
 *
 * Das steht als eigener Test da, weil der Briefing-Auftrag ausdruecklich
 * danach fragt und weil ein Produkt, das in einer Aufgabe im Namen einer
 * Person handelt, sonst die leere Liste des Vorgaengers bekaeme.
 */
it('gibt einer Aufgabe im Arbeiter nicht die leere Liste der vorigen Aufgabe', function () {
    lebensdauerEingerichtet();
    lebensdauerQuellenAngemeldet();
    lebensdauerZaehler($aufrufe);

    // --- Aufgabe 1: ohne Person, der Normalfall im Arbeiter.
    //
    // Diese Zeile prueft NICHT die Wache — das tut Test 1 mit dem Zaehler, und
    // zwar als einziger. Gemessen bei der Mutationsprobe am 17.11.2026: Nimmt
    // man den Wache-Riegel heraus, bleibt diese Zeile gruen, weil die Attrappe
    // ohne Person ohnehin eine leere Liste meldet (`default => []`). Der
    // zweite Riegel verdeckt den ersten. Behauptet wird hier allein, dass
    // Aufgabe 2 nicht bekommt, was Aufgabe 1 hinterlassen hat.
    expect(app(TaskSourceRegistry::class)->all())->toBe([]);

    // --- Der Griff zwischen zwei Aufgaben, siehe QueueServiceProvider.
    app()->forgetScopedInstances();

    // --- Aufgabe 2: ein Produkt handelt im Namen einer Person.
    test()->actingAs(lebensdauerPersonA());

    expect(array_keys(app(TaskSourceRegistry::class)->all()))->toBe(['crm', 'manager']);
});

/**
 * Und die Gegenprobe zur Bindung selbst: Sie ist `scoped` und nicht `bind`.
 *
 * Ohne diesen Test wuerde auch ein `bind()` alle vier oben gruen machen — und
 * damit waere die Ersparnis weg, die den ganzen Lazy-Aufbau traegt: Jede
 * Aufloesung baute neu und fragte neu. Test 3 misst das schon mit, aber nur
 * nebenbei; hier steht es als eigene Behauptung.
 */
it('bleibt innerhalb einer Anfrage dieselbe Instanz, ist also kein bind()', function () {
    lebensdauerQuellenAngemeldet();

    expect(app(TaskSourceRegistry::class))->toBe(app(TaskSourceRegistry::class));
});
