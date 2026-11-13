<?php

use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\Tasks\Sources\AnbieterMerker;
use Peppermint\Tasks\Sources\BrueckenSubjektquelle;
use Peppermint\Tasks\Sources\BrueckenSubjektquellen;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\Person;

/*
 * Die ZAHL der Netzaufrufe je Seitenaufbau (Bug #1469).
 *
 * Gemessen am 13.11.2026 waren es sechs: zwei aus der Anmeldung, zwei aus
 * `forPersons()` und zwei aus `forSubjects()` über dieselben beiden
 * Brücken-Quellen. Jeder davon mit der Zeitgrenze aus `gateway.timeout` —
 * vorbelegt 20 Sekunden —, womit aus einer nicht erreichbaren Nabe kein
 * langsamer, sondern ein ausgefallener Seitenaufbau wird.
 *
 * Diese Datei nagelt die Zahl fest. Ohne sie wäre der Merker eine
 * Verbesserung, von der beim nächsten Umbau niemand weiss: Nichts an der
 * Antwort ändert sich, wenn er wegfällt — nur die Rechnung.
 */

/** Eine angemeldete Person. Ohne sie wird gar nicht erst gefragt. */
function merkerAngemeldet(): void
{
    test()->actingAs(new Person(['id' => 1, 'name' => 'Bastian']));
}

/**
 * Eine eingerichtete Brücke und jemand davor.
 *
 * Wortgleich zu `bitteEingerichtet()` in `BrueckenSubjektquelleTest` und aus
 * demselben Grund eine ECHTE Instanz statt einer Attrappe: `Bruecke::
 * eingerichtet()` fragt, ob sich der Manager AUFLÖSEN lässt, und Mockery kann
 * dieselbe Mock-Klasse nicht zweimal im Prozess erzeugen.
 */
function merkerEingerichtet(): void
{
    Bruecke::vergessen();
    merkerAngemeldet();

    $tokens = new OAuthTokenProvider([]);

    app()->instance(AiBrainManager::class, new AiBrainManager(
        config: [],
        tokens: $tokens,
        publisher: new EventPublisher([], $tokens, 'tasks-test'),
    ));
}

/**
 * Zählt jeden Gang über die Leitung mit und antwortet mit zwei Systemen.
 *
 * Gezählt wird hier und nicht über `->once()`: Eine Mockery-Erwartung fällt
 * erst beim Aufräumen um und sagt dann „received 2 times" — die ZAHL steht
 * damit nicht im Test, sondern in der Fehlermeldung. Ein Zähler macht die
 * Behauptung lesbar.
 */
function merkerZaehler(?int &$aufrufe): void
{
    $aufrufe = 0;

    AiBrain::shouldReceive('providers')->andReturnUsing(function (string $vorgang) use (&$aufrufe): array {
        $aufrufe++;

        return [
            ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
            ['slug' => 'peppermint-manager', 'key' => 'manager', 'label' => 'Manager'],
        ];
    });
}

afterEach(function () {
    Bruecke::vergessen();
});

it('fragt dieselbe Fähigkeit nur EINMAL je Anfrage', function () {
    merkerEingerichtet();
    merkerZaehler($aufrufe);

    $merker = app(AnbieterMerker::class);

    $erste = $merker->anbieter(BrueckenSubjektquelle::CAPABILITY);
    $zweite = $merker->anbieter(BrueckenSubjektquelle::CAPABILITY);

    // Die Antwort ist dieselbe — innerhalb EINER Anfrage kann sie es gar
    // nicht anders sein. Nur geholt wird sie einmal.
    expect($aufrufe)->toBe(1)
        ->and($zweite)->toBe($erste)
        ->and(array_keys($erste))->toBe(['peppermint-crm', 'peppermint-manager']);
});

it('fragt zwei verschiedene Fähigkeiten zweimal', function () {
    merkerEingerichtet();
    merkerZaehler($aufrufe);

    $merker = app(AnbieterMerker::class);

    $merker->anbieter(BrueckenSubjektquelle::CAPABILITY);
    $merker->anbieter(BrueckenSubjektquelle::CAPABILITY_PERSON);
    $merker->anbieter(BrueckenSubjektquelle::CAPABILITY);

    // Je Vorgang einer, und zwar wirklich je Vorgang: Ein Merker, der die
    // Fähigkeit nicht mitführte, hielte die Antwort der einen Frage für die
    // der anderen — und ein System, das nur eine von beiden beantwortet,
    // stünde dann in der falschen Liste.
    expect($aufrufe)->toBe(2);
});

/**
 * Der ganze Seitenaufbau: 2 statt 6.
 *
 * Das ist die Zahl aus dem Fehlerbericht, nachgestellt — Anmeldung, dann die
 * beiden Listen, die eine Maske seit #9887 beide braucht.
 */
it('kostet einen ganzen Seitenaufbau zwei Netzaufrufe statt sechs', function () {
    merkerEingerichtet();
    merkerZaehler($aufrufe);

    $register = new TaskSourceRegistry;

    // 1. Die Anmeldung: zwei Vorgänge, zwei Aufrufe.
    expect((new BrueckenSubjektquellen)->melden($register))->toBe(2);

    // 2. und 3. Die beiden Listen. Beide bauen auf `available()`, das
    // `isAvailable()` über JEDE Quelle laufen lässt — vorher also vier
    // weitere Aufrufe.
    expect(array_keys($register->forPersons()))->toBe(['crm', 'manager'])
        ->and(array_keys($register->forSubjects()))->toBe(['crm', 'manager']);

    expect($aufrufe)->toBe(2);
});

/**
 * Der Unterschied zum Singleton — und der Grund, warum der Merker überhaupt
 * erlaubt ist.
 *
 * `GatewayClient::providers()` lehnt einen Zwischenspeicher begründet ab: Ein
 * neu angebundenes System erschiene verspätet, ein abgeschaltetes wirkte nach.
 * Ein Singleton lebt unter Octane so lange wie der Arbeiter — Stunden — und
 * wäre genau dieser Zwischenspeicher, nur ohne Frist.
 *
 * `forgetScopedInstances()` ist das, was Laravel zwischen zwei
 * Octane-Anfragen tut. Danach muss wieder gefragt werden.
 */
it('überlebt das Ende der Anfrage NICHT', function () {
    merkerEingerichtet();
    merkerZaehler($aufrufe);

    $erster = app(AnbieterMerker::class);
    $erster->anbieter(BrueckenSubjektquelle::CAPABILITY);
    app(AnbieterMerker::class)->anbieter(BrueckenSubjektquelle::CAPABILITY);

    expect($aufrufe)->toBe(1);

    // Anfrageende.
    app()->forgetScopedInstances();

    $zweiter = app(AnbieterMerker::class);
    $zweiter->anbieter(BrueckenSubjektquelle::CAPABILITY);

    // Ein neuer Merker, und gefragt wurde erneut. Wäre die Bindung ein
    // `singleton()`, stünde hier dieselbe Instanz und der Zähler bliebe auf 1
    // — und damit wäre aus dem Merker für eine Anfrage der Zwischenspeicher
    // geworden, den die Brücke ablehnt.
    expect($zweiter)->not->toBe($erster)
        ->and($aufrufe)->toBe(2);
});

/**
 * Und dasselbe für den Weg, den ein Seitenaufbau wirklich geht.
 *
 * Die Zusicherung aus dem Docblock von `isAvailable()` — „zwischen
 * registriert und gefragt liegt Zeit: Ein Seitenaufbau pflegt eine Liste, ein
 * Klick fragt sie ab" — hängt daran: Der Klick ist eine ANDERE Anfrage, und
 * dort wird wieder gemessen. Verliert das System die Fähigkeit zwischendurch,
 * verschwindet die Quelle.
 */
it('misst in der nächsten Anfrage neu, wenn ein System die Fähigkeit verliert', function () {
    merkerEingerichtet();

    $antwort = [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']];

    AiBrain::shouldReceive('providers')->andReturnUsing(function () use (&$antwort): array {
        return $antwort;
    });

    $quelle = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');

    expect($quelle->isAvailable())->toBeTrue();

    // Die Freigabe wird entzogen — zwischen zwei Anfragen.
    $antwort = [];
    app()->forgetScopedInstances();

    expect($quelle->isAvailable())->toBeFalse();
});

/**
 * Auch die leere Antwort wird gemerkt — gerade sie.
 *
 * Ein Fehlschlag beim Fragen IST die leere Liste (`AiBrain::providers()` sagt
 * das zu). Würde sie nicht gemerkt, kostete ausgerechnet der Ausfall — der
 * Fall, in dem der Seitenaufbau an der Zeitgrenze hängt — jeden weiteren
 * Aufruf noch einmal volle 20 Sekunden.
 */
it('merkt sich auch die leere Antwort eines Ausfalls', function () {
    merkerEingerichtet();

    $aufrufe = 0;

    AiBrain::shouldReceive('providers')->andReturnUsing(function () use (&$aufrufe): array {
        $aufrufe++;

        return [];
    });

    $merker = app(AnbieterMerker::class);

    $merker->anbieter(BrueckenSubjektquelle::CAPABILITY);
    $merker->anbieter(BrueckenSubjektquelle::CAPABILITY);

    expect($aufrufe)->toBe(1)
        ->and($merker->anbieter(BrueckenSubjektquelle::CAPABILITY))->toBe([]);
});
