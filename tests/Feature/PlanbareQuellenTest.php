<?php

use Peppermint\AiBrainBridge\AiBrainManager;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Events\EventPublisher;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Exceptions\InvalidTimeSpan;
use Peppermint\Tasks\Sources\BrueckenSubjektquelle;
use Peppermint\Tasks\Sources\BrueckenSubjektquellen;
use Peppermint\Tasks\Sources\PlannedBlock;
use Peppermint\Tasks\Sources\PlansForeignTasks;
use Peppermint\Tasks\Sources\RemoteTaskSource;
use Peppermint\Tasks\Sources\SourceFailure;
use Peppermint\Tasks\Sources\TaskSource;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Sources\TimeSpan;
use Peppermint\Tasks\Tests\Support\ErfundenePlanquelle;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;
use Peppermint\Tasks\Tests\Support\ErfundeneSchreibquelle;
use Peppermint\Tasks\Tests\Support\Person;

/*
| Eine fremde Aufgabe einplanen — der Vertrag (#9898, Schritt 1).
|
| Bis zum 16.11.2026 konnte ueber die Bruecke kein Produkt in ein anderes
| schreiben: `BrueckenSubjektquelle` war rein lesend, und die schreibende
| Haelfte eines Aufgabenvertrags gab es im ganzen Verbund nur in AI Brain —
| der Nabe, nicht einem Produkt. Eine Maske, die „welches System, dann welche
| Aufgabe" fragt, endete damit genau dort, wo sie den Zeitraum hinschreiben
| muesste.
|
| Die Formentscheidung dahinter ist ein ZUSAETZLICHER Vertrag, per `instanceof`
| gefragt, und nicht eine Erweiterung von `WritableTaskSource`: Abstrakte
| Methoden hinzuzufuegen braeche jede Umsetzung, und AI Brain hat eine.
|
| Der Preis dieser Form steht im Wiki, und er ist der Grund, warum die
| Waechtertests unten keine Zierde sind: Ein optionaler Vertrag bricht nichts,
| er FEHLT nur. In AI Brain setzte `BrainWerkzeugquelle` den Suchvertrag der
| Werkzeugleiste nicht um, und niemand merkte es — die Masken gingen auf, die
| Trefferliste blieb leer. Ohne Waechtertest ist die Entscheidung eine Meinung.
*/

/**
 * Tut so, als wäre die Brücke eingerichtet — und als sässe jemand davor.
 *
 * Eigene Fassung und nicht die aus `BrueckenSubjektquelleTest`: Pest lädt alle
 * Testdateien in denselben Prozess, aber ein Lauf über NUR diese Datei lädt die
 * andere nicht — und eine Datei, die sich allein nicht ausführen lässt, ist die
 * Datei, die beim nächsten Einzelnachweis im Weg steht.
 */
function planungBereit(): void
{
    Bruecke::vergessen();
    test()->actingAs(new Person(['id' => 1, 'name' => 'Bastian']));

    $tokens = new OAuthTokenProvider([]);

    app()->instance(AiBrainManager::class, new AiBrainManager(
        config: [],
        tokens: $tokens,
        publisher: new EventPublisher([], $tokens, 'tasks-test'),
    ));
}

/**
 * Die drei Vorgänge, die ein antwortendes System melden kann.
 *
 * @param  array<int, string>  $vorgaenge
 */
function registerMeldet(array $systeme, array $vorgaenge): void
{
    $zeilen = array_map(
        static fn (string $slug) => ['slug' => $slug, 'key' => $slug, 'label' => ucfirst($slug)],
        $systeme,
    );

    foreach ($vorgaenge as $vorgang) {
        AiBrain::shouldReceive('anbieterMitGrund')->with($vorgang)->andReturn(anbieterAntwort($zeilen));
    }

    // Was nicht ausdrücklich genannt ist, bedient niemand. Ohne diese Zeile
    // antwortet die Attrappe auf einen nicht erwarteten Vorgang mit einem
    // Fehler statt mit „keiner" — und der Test prüfte dann die Attrappe.
    AiBrain::shouldReceive('anbieterMitGrund')->andReturn(anbieterAntwort([]));
}

afterEach(function () {
    // Die Brücke merkt ihre Antwort für die Dauer eines Aufrufs. Ohne das
    // Vergessen trüge der nächste Test die Antwort des vorigen.
    Bruecke::vergessen();
});

/*
|--------------------------------------------------------------------------
| Der Wächter — über jede registrierte Quelle, nicht über eine erfundene
|--------------------------------------------------------------------------
*/

it('traegt den Planungsvertrag an JEDER Quelle, die das Paket wirklich anmeldet', function () {
    planungBereit();

    // Der echte Anmeldeweg, nicht eine Attrappe: `BrueckenSubjektquellen`
    // ist die Stelle, über die ein Produkt seine Fremdquellen bekommt. Ein
    // Wächter, der eine selbstgebaute Quelle prüft, prüft die eigene Annahme.
    registerMeldet(
        ['peppermint-crm', 'peppermint-manager', 'peppermint-verwaltung'],
        [BrueckenSubjektquelle::CAPABILITY, BrueckenSubjektquelle::CAPABILITY_PERSON],
    );

    $register = new TaskSourceRegistry;
    $angemeldet = (new BrueckenSubjektquellen)->melden($register);

    // Die Untergrenze zuerst. Ein Wächter über eine leere Menge ist immer
    // grün und deckt nichts ab — und genau das passiert, wenn der Anmeldeweg
    // sich ändert und niemand nachsieht.
    expect($angemeldet)->toBe(3, 'Ohne angemeldete Quellen prueft dieser Waechter nichts.');

    $ohneVertrag = array_keys(array_filter(
        $register->all(),
        static fn (TaskSource $quelle) => ! $quelle instanceof PlansForeignTasks,
    ));

    expect($ohneVertrag)->toBe([],
        'Eine angemeldete Bruecken-Quelle ohne Planungsvertrag nimmt der Maske lautlos '
        .'den Zeitraum-Schritt weg: die Systemliste steht da, der Schritt danach fehlt.');
});

it('nennt jede ausgelieferte Quellenklasse entweder planbar oder ausdruecklich nicht', function () {
    // Die gelesene Menge wird AUFGEZAEHLT und nicht geraten — rekursiv über
    // das Verzeichnis. Eine namentliche Liste im Test wäre eine zweite Liste,
    // und die zweite Liste pflegt niemand (Wiki „Wächtertests: erst messen,
    // dann festhalten — und die gelesene Menge hinterfragen").
    $gefunden = quellenklassenDesPakets();

    expect(count($gefunden))->toBeGreaterThan(1,
        'Findet der Waechter fast nichts, zeigt er ins Leere — etwa nach einer Umbenennung.');

    expect(pruefePlanungsvertrag($gefunden))->toBe([],
        'Eine neue Quelle muss sich entscheiden: Planung umsetzen oder in der '
        .'Ausnahmeliste begruenden. Lautlos fehlen darf der Vertrag nicht.');
});

it('schlaegt an, wenn eine Quelle den Vertrag nicht hat und auch nicht genannt ist', function () {
    // Die Gegenprobe. Ohne sie weiss niemand, ob der Wächter oben seine Regel
    // wirklich prüft oder etwas anderes — zwei Wächter an einem Tag waren
    // schon grün, während die Regel auf dem Gerät nicht stimmte.
    $erfunden = ErfundeneQuelle::class;

    expect(pruefePlanungsvertrag([$erfunden]))->toBe([$erfunden]);
});

/**
 * Jede konkrete Quellenklasse, die dieses Paket ausliefert.
 *
 * Aus dem Verzeichnis, nicht aus einer Aufzählung: Was der Wächter nicht
 * liest, existiert für ihn nicht. `src/` ganz und nicht `src/Sources/` —
 * eine Quelle darf überall liegen, und die Annahme „sie liegt im
 * Quellen-Ordner" ist genau die Sorte Annahme, die einen Wächter ins Leere
 * zeigen lässt.
 *
 * @return array<int, class-string<TaskSource>>
 */
function quellenklassenDesPakets(): array
{
    $wurzel = dirname(__DIR__, 2).'/src';

    $dateien = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel));

    $klassen = [];

    foreach ($dateien as $datei) {
        if (! $datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }

        $relativ = substr($datei->getPathname(), strlen($wurzel) + 1, -4);
        $klasse = 'Peppermint\\Tasks\\'.str_replace('/', '\\', $relativ);

        if (! class_exists($klasse)) {
            continue;
        }

        $spiegel = new ReflectionClass($klasse);

        // Abstrakte zählen nicht: `TaskSource` und `WritableTaskSource` sind
        // die Verträge selbst, und ein Vertrag setzt keinen zweiten um.
        if ($spiegel->isAbstract() || ! $spiegel->isSubclassOf(TaskSource::class)) {
            continue;
        }

        $klassen[] = $klasse;
    }

    sort($klassen);

    return $klassen;
}

/**
 * Welche der übergebenen Quellen den Planungsvertrag lautlos vermissen.
 *
 * Die Ausnahmen stehen ausdrücklich IM Test und nicht in einer
 * Konfigurationsdatei daneben — damit die Entscheidung im Diff sichtbar ist.
 * Das ist die Lehre aus dem Navigations-Wächter (Bug #5868): Eine Ausnahme,
 * die man nicht im Diff sieht, ist eine Ausnahme, die niemand mehr prüft.
 *
 * @param  array<int, class-string>  $klassen
 * @return array<int, class-string>
 */
function pruefePlanungsvertrag(array $klassen): array
{
    $ohnePlanung = [
        // Sein eigener Klassenkopf sagt es: „Read-only, deliberately, in this
        // round … building it along the way would mean shipping a write path
        // into other people's systems that nothing has exercised." Diese
        // Quelle spricht über `RemoteTaskSystem` mit beliebigen Transporten;
        // ihr einen Schreibweg zu geben, den kein Produkt benutzt, wäre genau
        // der ungemessene Weg, den sie ablehnt.
        RemoteTaskSource::class => 'lesend, ausdruecklich — siehe Klassenkopf',
    ];

    return array_values(array_filter(
        $klassen,
        static fn (string $klasse) => ! is_a($klasse, PlansForeignTasks::class, true)
            && ! array_key_exists($klasse, $ohnePlanung),
    ));
}

/*
|--------------------------------------------------------------------------
| Der Registerfilter
|--------------------------------------------------------------------------
*/

it('haelt eine Quelle ohne Planungsvertrag aus der Planungsliste heraus', function () {
    // Der Kern der Formentscheidung: Schreiben und Planen sind ZWEI Tatsachen
    // über ein Produkt. Eine Quelle, in die man schreiben darf, ist deshalb
    // nicht automatisch eine, in der man Zeit einplanen kann — die Verwaltung
    // ist der umgekehrte Fall und nimmt keine neue Aufgabe von draussen an.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('nur-lesen'));
    $register->register(new ErfundeneSchreibquelle('nur-schreiben'));
    $register->register(new ErfundenePlanquelle('darf-planen'));

    expect(array_keys($register->plannable()))->toBe(['darf-planen'])
        ->and($register->all())->toHaveCount(3);
});

it('nennt ein System planbar, auch wenn es gerade nicht erreichbar ist — der Grund kommt beim Versuch', function () {
    // Umgeschrieben mit #10056. Bis zum 19.11.2026 stand hier das GEGENTEIL:
    // „bietet zum Planen nichts an, was gar nicht erreichbar ist", weil
    // `plannable()` auf `available()` aufbaute. Das war die strengere Lesart,
    // aber die falsche: `isAvailable()` fragt das Faehigkeits-Register, und
    // die Bruecke bildet dort Nabe weg, Fehlerantwort, fehlende Freigabe und
    // fehlende Faehigkeit auf DIESELBE leere Liste ab. Ein System
    // verschwand damit aus der Maske, und niemand konnte mehr sagen, ob man
    // warten oder jemanden holen muss.
    //
    // „Laesst sich aus diesem System ueberhaupt einplanen?" ist lokal und
    // statisch — `instanceof` —, und „hat es geklappt?" beantwortet der
    // Versuch, samt Grund. Der Vier-Lagen-Test unten weist das an der echten
    // Brueckenquelle nach.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundenePlanquelle('weg', verfuegbar: false));

    expect(array_keys($register->plannable()))->toBe(['weg'])
        // Die lesende Seite bleibt, wie sie ist: Aufgaben eines Systems, das
        // nicht antwortet, werden weiter nicht gefragt.
        ->and($register->available())->toBeEmpty();
});

it('filtert die drei Vorgaenge nicht vorab, sondern laesst sie beim Versuch scheitern', function () {
    // Umgeschrieben mit #10056. Vorher hiess dieser Test „trennt die drei
    // Vorgaenge, statt sie zu einem Schalter zu machen" und erwartete, dass
    // `plannableFor(create)` die Verwaltung herausfiltert — ueber `offers()`,
    // also ueber das Netz. Dieselbe Antwort `false` steht dort aber auch fuer
    // „Nabe weg" und „Freigabe fehlt", und die Maske haette die Verwaltung
    // in allen drei Faellen gleich stumm weggelassen.
    //
    // Die drei Vorgaenge bleiben getrennt — im Vertrag (drei Faehigkeiten,
    // kein Schalter) und in `offers()`, das weiter misst, was die Gegenseite
    // gerade anbietet. Nur fragt die Liste einer Maske es nicht mehr VOR dem
    // Zeichnen: Das Nein kommt aus `plan()`, als `not_offered`.
    $verwaltung = new ErfundenePlanquelle('nur-aendern', vorgaenge: [
        PlansForeignTasks::CAPABILITY_UPDATE,
        PlansForeignTasks::CAPABILITY_DELETE,
    ]);
    $register = new TaskSourceRegistry;
    $register->register($verwaltung);

    foreach (PlansForeignTasks::CAPABILITIES as $vorgang) {
        expect(array_keys($register->plannableFor($vorgang)))->toBe(['nur-aendern']);
    }

    expect($verwaltung->offers(PlansForeignTasks::CAPABILITY_CREATE))->toBeFalse()
        ->and($verwaltung->offers(PlansForeignTasks::CAPABILITY_UPDATE))->toBeTrue();
});

it('wirft bei einem Vorgang, den es nicht gibt, statt eine leere Liste zu liefern', function () {
    // Eine leere Liste waere die falsche Antwort in der einen Form, die man
    // nicht sehen kann: Die Maske zeigte kein System und das liest sich als
    // „niemand bietet das an" — eine Behauptung, keine Abwesenheit.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundenePlanquelle);

    expect(fn () => $register->plannableFor('tasks.schedule.list'))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Vier Lagen, eine Antwort vorher, vier Gruende beim Versuch (#10056)
|--------------------------------------------------------------------------
|
| Ueber die Bruecke liefert `providers()` in vier Lagen dasselbe: eine leere
| Liste. Nabe weg, Freigabe weg, Faehigkeit fehlt — und nur die vierte Lage,
| „alles in Ordnung", unterscheidet sich. Eine Vorpruefung ueber diesen Weg
| bildet drei Gruende auf einen ab.
|
| Nach der Umstellung muss `plannable()` in ALLEN VIER Lagen dieselbe Antwort
| geben, ohne das Netz zu fragen, und der Unterschied wird erst beim Versuch
| sichtbar: als Fehlerklasse, die die Maske in vier Saetze uebersetzt.
*/

dataset('lagen der nabe', [
    'Nabe weg' => [
        [],
        ['ok' => false, 'error' => SourceFailure::UNREACHABLE, 'message' => 'AI Brain nicht erreichbar: Connection refused'],
        SourceFailure::UNREACHABLE,
    ],
    'Freigabe weg' => [
        [],
        ['ok' => false, 'error' => SourceFailure::NOT_ALLOWED, 'message' => 'Fuer „tasks.schedule.create" ist dieses System nicht freigeschaltet.'],
        SourceFailure::NOT_ALLOWED,
    ],
    'Faehigkeit fehlt' => [
        [],
        ['ok' => false, 'error' => SourceFailure::NOT_OFFERED, 'message' => '„tasks.schedule.create" bietet dieses System nicht an.'],
        SourceFailure::NOT_OFFERED,
    ],
    'alles in Ordnung' => [
        [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']],
        ['ok' => true, 'data' => [
            'id' => 42,
            'title' => 'Angebot Meier',
            'starts_at' => '2026-11-17T09:00:00+01:00',
            'ends_at' => '2026-11-17T11:00:00+01:00',
            'kind' => 'crm_task',
        ]],
        null,
    ],
]);

it('gibt in jeder Lage der Nabe dieselbe Antwort auf „planbar?" und zeigt den Unterschied erst beim Versuch', function (array $anbieter, array $antwort, ?string $grund) {
    planungBereit();

    // Jede Frage an das Register wird mitgeschrieben. `plannable()` darf
    // keine stellen — nicht weil es teuer waere, sondern weil die Antwort
    // dort in drei von vier Lagen luegt.
    $gefragt = [];
    AiBrain::shouldReceive('anbieterMitGrund')->andReturnUsing(anbieterAntwortend(function (string $vorgang) use (&$gefragt, $anbieter) {
        $gefragt[] = $vorgang;

        return $anbieter;
    }));
    AiBrain::shouldReceive('gateway')->andReturn($antwort);

    $register = new TaskSourceRegistry;
    $register->register(new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'));

    // VORHER: in allen vier Lagen dieselbe Antwort, und kein Netz.
    expect(array_keys($register->plannable()))->toBe(['crm']);
    foreach (PlansForeignTasks::CAPABILITIES as $vorgang) {
        expect(array_keys($register->plannableFor($vorgang)))->toBe(['crm']);
    }
    expect($gefragt)->toBe([]);

    // Was das Register heute NICHT mehr fragt, und warum: `offers()` sagt in
    // den ersten drei Lagen dasselbe `false`. Es misst richtig — nur kann
    // niemand aus diesem `false` lesen, was zu tun ist.
    expect($register->plannable()['crm']->offers(PlansForeignTasks::CAPABILITY_CREATE))
        ->toBe($grund === null);

    // BEIM VERSUCH: vier unterscheidbare Ausgaenge.
    try {
        $block = $register->plannable()['crm']->plan(1, 11, new TimeSpan(
            '2026-11-17T09:00:00+01:00',
            '2026-11-17T11:00:00+01:00',
        ));
        $fehler = null;
    } catch (ForeignSourceFailed $e) {
        $block = null;
        $fehler = $e;
    }

    if ($grund === null) {
        expect($fehler)->toBeNull()
            ->and($block?->id)->toBe(42);
    } else {
        expect($block)->toBeNull()
            ->and($fehler?->errorClass)->toBe($grund);
    }
})->with('lagen der nabe');

/*
|--------------------------------------------------------------------------
| Der Zeitraum — und was NICHT mitgeht
|--------------------------------------------------------------------------
*/

it('schickt nur Beginn und Ende, keine Person und keine Art', function () {
    $zeitraum = new TimeSpan('2026-11-17T09:00:00+01:00', '2026-11-17T11:00:00+01:00');

    // Der wichtigere Teil des Vertrags ist, was NICHT uebertragen wird: keine
    // Person (sie reist am Komm-Layer mit — eine Kennung im Argument waere das
    // Recht, jemandem etwas in den Kalender zu schreiben), keine Art (jedes
    // System hat genau eine und nimmt seine eigene), kein Titel, kein
    // `all_day`, keine lokale Terminkennung.
    expect($zeitraum->toArray())->toBe([
        'starts_at' => '2026-11-17T09:00:00+01:00',
        'ends_at' => '2026-11-17T11:00:00+01:00',
    ]);
});

it('weist eine nackte Ortszeit ab, statt sie weiterzuschicken', function () {
    // Zweimal gemessen und beide Male teuer: erst UTC statt Anzeigezone — zwei
    // Stunden zu frueh in jedem fremd angelegten Termin —, dann die daraus
    // gezogene falsche Lehre. Eine Ortszeit ist die Sorte Angabe, bei der zwei
    // Systeme sich stillschweigend um zwei Stunden uneinig sind.
    expect(fn () => new TimeSpan('2026-11-17T09:00:00', '2026-11-17T11:00:00+01:00'))
        ->toThrow(InvalidTimeSpan::class);

    expect(fn () => new TimeSpan('2026-11-17T09:00:00+01:00', '2026-11-17 11:00:00'))
        ->toThrow(InvalidTimeSpan::class);

    // Ein bloses Datum ebenso: `strtotime` haette die Serverzone eingesetzt,
    // und das sieht aus wie eine Angabe.
    expect(fn () => new TimeSpan('2026-11-17', '2026-11-18'))
        ->toThrow(InvalidTimeSpan::class);
});

it('nimmt Z und eine Zone ohne Doppelpunkt an', function () {
    // Beides ist ISO-8601 mit Zone. Nur `+01:00` zu nehmen waere eine Regel
    // ueber die Schreibweise, nicht ueber die Aussage.
    expect(new TimeSpan('2026-11-17T08:00:00Z', '2026-11-17T10:00:00Z'))
        ->startsAt->toBe('2026-11-17T08:00:00Z')
        ->and(new TimeSpan('2026-11-17T09:00:00+0100', '2026-11-17T11:00:00+0100'))
        ->endsAt->toBe('2026-11-17T11:00:00+0100');
});

it('rechnet nicht um, sondern reicht die Zone durch, die gewaehlt wurde', function () {
    // Nach UTC umzurechnen hiesse, dem anderen System eine Zone zu nennen, die
    // niemand gewaehlt hat — der zweite der beiden gemessenen Fehlschlaege.
    $zeitraum = new TimeSpan('2026-11-17T09:00:00+01:00', '2026-11-17T11:00:00+01:00');

    expect($zeitraum->toArray()['starts_at'])->toBe('2026-11-17T09:00:00+01:00');
});

it('weist einen Zeitraum ab, der nicht nach seinem Beginn endet', function () {
    // Geplante Arbeit ist eine Spanne von Stunden. Ein Block ohne Dauer
    // zeichnete sich im Raster als Strich, den niemand greifen kann — und
    // saehe dabei wie ein gelungener Plan aus.
    expect(fn () => new TimeSpan('2026-11-17T11:00:00+01:00', '2026-11-17T09:00:00+01:00'))
        ->toThrow(InvalidTimeSpan::class);

    expect(fn () => new TimeSpan('2026-11-17T09:00:00+01:00', '2026-11-17T09:00:00+01:00'))
        ->toThrow(InvalidTimeSpan::class);
});

it('vergleicht ueber Zonen hinweg und nicht ueber die Zeichenkette', function () {
    // 09:00+02:00 ist FRUEHER als 09:30+01:00 — als Text gelesen waere das
    // Gegenteil plausibel. Ein Zeitraum ueber eine Zonengrenze (Reise,
    // Sommerzeitwechsel) ist kein erfundener Fall.
    expect(new TimeSpan('2026-11-17T09:00:00+02:00', '2026-11-17T09:30:00+01:00'))
        ->endsAt->toBe('2026-11-17T09:30:00+01:00');

    expect(fn () => new TimeSpan('2026-11-17T09:30:00+01:00', '2026-11-17T09:00:00+02:00'))
        ->toThrow(InvalidTimeSpan::class);
});

/*
|--------------------------------------------------------------------------
| Der Block, der zurueckkommt
|--------------------------------------------------------------------------
*/

it('liest die Terminzeile des anderen Systems und nicht die geschickte Anfrage', function () {
    // Dieselbe Regel wie bei `WritableTaskSource::create()`: Was zurueckkommt,
    // ist die Fassung des anderen Systems. Es wendet seine Art an, leitet den
    // Titel aus seiner eigenen Aufgabe ab und haengt seine Kennung an.
    $block = PlannedBlock::fromRow('crm', PlansForeignTasks::CAPABILITY_CREATE, [
        'id' => 42,
        'title' => 'Angebot Meier — Bearbeitung',
        'starts_at' => '2026-11-17T09:00:00+01:00',
        'ends_at' => '2026-11-17T11:00:00+01:00',
        'kind' => 'crm_task',
        'kind_label' => 'Aufgaben-Zeitblock',
        'url' => 'https://crm.test/deals/8',
        'all_day' => false,
        'user_email' => 'bastian@peppermint.test',
    ]);

    expect($block->title)->toBe('Angebot Meier — Bearbeitung')
        ->and($block->kind)->toBe('crm_task')
        // Die Art unuebersetzt: Sie gehoert dem System, das sie definiert hat.
        ->and($block->toArray()['kind_label'])->toBe('Aufgaben-Zeitblock')
        // Die Kennung traegt die Quelle davor. Eine lokale `calendar_events.id`
        // und eine fremde Blockkennung sind beide blanke Zahlen, und der
        // Vorgang, der die falsche nimmt, ist ein Loeschen.
        ->and($block->toArray()['id'])->toBe('crm:42')
        ->and($block->toArray()['foreign_id'])->toBe(42);
});

it('reicht durch, was das andere System sonst noch sagt', function () {
    // Ungefiltert, wie bei `ForeignTask`: Was das Haus des Nachbarn verlaesst,
    // entscheidet der NACHBAR in seinem antwortenden Werkzeug. Eine Liste
    // erlaubter Felder hier waere eine zweite Filterung an der falschen Stelle
    // — und jedes neue Anzeigefeld braeuchte eine Paketfreigabe.
    $block = PlannedBlock::fromRow('manager', PlansForeignTasks::CAPABILITY_CREATE, [
        'id' => 1,
        'starts_at' => '2026-11-17T09:00:00+01:00',
        'ends_at' => '2026-11-17T11:00:00+01:00',
        'all_day' => false,
        'location' => 'Buero',
        'user_email' => 'bastian@peppermint.test',
    ]);

    expect($block->extra)->toBe([
        'all_day' => false,
        'location' => 'Buero',
        'user_email' => 'bastian@peppermint.test',
    ]);
});

it('wirft tool_failed, wenn die Antwort kein Zeitblock ist', function () {
    // Waeren Beginn und Ende hier auffuellbar, kaeme aus einem sich falsch
    // verhaltenden System ein Objekt zurueck, das jede Typpruefung besteht und
    // im Raster als Eintrag ohne Stunden erscheint — und ein Mensch liest das
    // als „eingeplant".
    try {
        PlannedBlock::fromRow('crm', PlansForeignTasks::CAPABILITY_CREATE, [
            'id' => 42,
            'title' => 'Ohne Zeiten',
        ]);
        $fehler = null;
    } catch (ForeignSourceFailed $e) {
        $fehler = $e;
    }

    expect($fehler)->not->toBeNull()
        // Eine der VIER vorhandenen Fehlerklassen, keine fuenfte: „Es
        // antwortet, und die Antwort ist ein Fehler" ist genau dieser Fall.
        ->and($fehler->errorClass)->toBe(SourceFailure::TOOL_FAILED)
        ->and($fehler->errorClass)->toBeIn(SourceFailure::REASONS)
        // Und die Meldung nennt, WAS fehlte. „Die Antwort war ungueltig"
        // schickt jemanden suchen.
        ->and($fehler->getMessage())->toContain('starts_at')
        ->and($fehler->getMessage())->toContain('ends_at');
});

/*
|--------------------------------------------------------------------------
| Die schreibende Haelfte der Bruecken-Quelle
|--------------------------------------------------------------------------
*/

it('plant eine fremde Aufgabe ein und schickt dabei genau vier Angaben', function () {
    planungBereit();

    AiBrain::shouldReceive('gateway')
        ->once()
        ->with(
            PlansForeignTasks::CAPABILITY_CREATE,
            [
                'task_id' => '11',
                'starts_at' => '2026-11-17T09:00:00+01:00',
                'ends_at' => '2026-11-17T11:00:00+01:00',
            ],
            'peppermint-crm',
        )
        ->andReturn(['ok' => true, 'data' => [
            'id' => 42,
            'title' => 'Angebot Meier',
            'starts_at' => '2026-11-17T09:00:00+01:00',
            'ends_at' => '2026-11-17T11:00:00+01:00',
            'kind' => 'crm_task',
        ]]);

    $quelle = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');

    $block = $quelle->plan(1, 11, new TimeSpan(
        '2026-11-17T09:00:00+01:00',
        '2026-11-17T11:00:00+01:00',
    ));

    // `task_id` und nicht `ticket_id` oder `subject_id`: Der Name steht schon
    // fest. Die Verwaltung plant Tickets und nimmt an ihrem schreibenden
    // Aufgabenwerkzeug `task_id` entgegen — „Die Kennung des Tickets in DIESEM
    // System". Ein zweiter Name waere eine Uebersetzung an der Stelle, an der
    // dieses Paket keine haben darf.
    expect($block->id)->toBe(42)
        ->and($block->sourceKey)->toBe('crm');
});

it('verschiebt ueber die Blockkennung, nicht ueber die Aufgabe', function () {
    planungBereit();

    AiBrain::shouldReceive('gateway')
        ->once()
        ->with(
            PlansForeignTasks::CAPABILITY_UPDATE,
            [
                'block_id' => '42',
                'starts_at' => '2026-11-17T13:00:00+01:00',
                'ends_at' => '2026-11-17T15:00:00+01:00',
            ],
            'peppermint-crm',
        )
        ->andReturn(['ok' => true, 'data' => [
            'id' => 42,
            'starts_at' => '2026-11-17T13:00:00+01:00',
            'ends_at' => '2026-11-17T15:00:00+01:00',
        ]]);

    $block = (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))
        ->move(1, 42, new TimeSpan('2026-11-17T13:00:00+01:00', '2026-11-17T15:00:00+01:00'));

    // Ein Vorgang fuer „aendern" und „verschieben": Ein Block hat Beginn, Ende
    // und Person und sonst nichts, worueber man streiten koennte. Wer eine
    // Stunde nach hinten zieht, will nicht, dass dabei Titel, Teilnehmer und
    // Serie mitgeschickt werden.
    expect($block->startsAt)->toBe('2026-11-17T13:00:00+01:00');
});

it('traegt einen Block aus und erwartet dafuer keine Zeile zurueck', function () {
    planungBereit();

    AiBrain::shouldReceive('gateway')
        ->once()
        ->with(
            PlansForeignTasks::CAPABILITY_DELETE,
            ['block_id' => '42'],
            'peppermint-crm',
        )
        // Erfolgreich und ohne Nutzlast — „was es nicht mehr gibt, kann nichts
        // zurueckgeben". Eine Zeile zu verlangen waere hier der Fehler.
        ->andReturn(['ok' => true]);

    (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))->unplan(1, 42);

    // Kommt die Methode ohne Wurf zurueck, ist der Block weg. Mehr gibt es
    // nicht zu pruefen, und das ist Absicht.
    expect(true)->toBeTrue();
});

it('plant keine Zeit fuer eine andere Person ein', function () {
    planungBereit();

    // Fail-closed, und zwar HIER und nicht drueben: Ein Aufruf, dessen
    // Identitaet unterwegs verlorengeht, macht die Antwort WEITER statt enger
    // (Bug #657 im Kalender). Beim Lesen hiesse das, fremde Aufgaben zu
    // zeigen; beim Planen hiesse es, einem anderen Menschen Zeit in den
    // Kalender zu schreiben — was die Weboberflaeche daneben auch nicht darf.
    AiBrain::shouldReceive('gateway')->never();

    $quelle = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');

    try {
        $quelle->plan(999, 11, new TimeSpan(
            '2026-11-17T09:00:00+01:00',
            '2026-11-17T11:00:00+01:00',
        ));
        $fehler = null;
    } catch (ForeignSourceFailed $e) {
        $fehler = $e;
    }

    // Und es wirft, statt stillzuhalten: Ein Schreibweg, der nichts tut und
    // nichts sagt, hinterlaesst einen Block, den jemand angelegt zu haben
    // GLAUBT und der nirgends existiert.
    expect($fehler)->not->toBeNull()
        ->and($fehler->errorClass)->toBe(SourceFailure::NOT_ALLOWED)
        // Warten hilft hier nicht, und die Maske soll keine Wiederholung
        // anbieten.
        ->and((new SourceFailure('crm', $fehler->getMessage(), $fehler->errorClass))
            ->mightPassByItself())->toBeFalse();
});

it('reicht die Fehlerklasse der Gegenstelle durch, statt leise nichts zu tun', function () {
    planungBereit();

    AiBrain::shouldReceive('gateway')->andReturn([
        'ok' => false,
        'error' => SourceFailure::NOT_ALLOWED,
        'message' => 'Fuer „tasks.schedule.create" ist dieses System nicht freigeschaltet.',
    ]);

    try {
        (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))->plan(1, 11, new TimeSpan(
            '2026-11-17T09:00:00+01:00',
            '2026-11-17T11:00:00+01:00',
        ));
        $fehler = null;
    } catch (ForeignSourceFailed $e) {
        $fehler = $e;
    }

    // „Nicht freigeschaltet" ist etwas anderes als „antwortet nicht", und der
    // Unterschied entscheidet, was ein Mensch als naechstes tun kann — warten
    // oder nachfragen. Und der TEXT der Gegenstelle reist mit: „Fehler" sagt
    // niemandem etwas.
    expect($fehler)->not->toBeNull()
        ->and($fehler->errorClass)->toBe(SourceFailure::NOT_ALLOWED)
        ->and($fehler->getMessage())->toContain('nicht freigeschaltet');
});

it('liest ok streng und haelt ein Zeichenketten-false nicht fuer Erfolg', function () {
    planungBereit();

    // `(bool) 'false' === true`. Gelesen als Erfolg kaeme eine leere Nutzlast
    // durch, und daraus wuerde ein Block ohne Zeiten — der Fehler, gegen den
    // `RemoteResult::fromArray()` streng liest.
    AiBrain::shouldReceive('gateway')->andReturn(['ok' => 'false', 'data' => null]);

    expect(fn () => (new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))
        ->plan(1, 11, new TimeSpan('2026-11-17T09:00:00+01:00', '2026-11-17T11:00:00+01:00')))
        ->toThrow(ForeignSourceFailed::class);
});

it('misst am Faehigkeits-Register, welche Vorgaenge ein System anbietet', function () {
    planungBereit();

    // Gemessen, nicht konfiguriert: Verliert die Gegenseite die Faehigkeit
    // oder wird die Freigabe entzogen, verschwindet der Knopf von selbst —
    // ohne dass jemand an einen Schalter denken muss.
    registerMeldet(['peppermint-crm'], [
        PlansForeignTasks::CAPABILITY_CREATE,
        PlansForeignTasks::CAPABILITY_UPDATE,
    ]);

    $quelle = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');

    expect($quelle->offers(PlansForeignTasks::CAPABILITY_CREATE))->toBeTrue()
        ->and($quelle->offers(PlansForeignTasks::CAPABILITY_UPDATE))->toBeTrue()
        // Der Loeschweg ist drueben nicht da — und genau das ist die offene
        // Frage 2 des Vorschlags (Bug #1480), nicht ein Fehler hier.
        ->and($quelle->offers(PlansForeignTasks::CAPABILITY_DELETE))->toBeFalse()
        // Ein Vorgang, der keiner der drei ist, bekommt hier `false` und
        // keinen Wurf: Laut wird die eine Stelle, die eine Maske fragt.
        ->and($quelle->offers('tasks.schedule.list'))->toBeFalse();
});

it('fragt dieselbe Frage innerhalb einer Anfrage nur einmal ueber die Leitung', function () {
    planungBereit();

    // Der {@see AnbieterMerker} haelt die Antwort fuer die Dauer EINER
    // Anfrage. Ohne ihn kostete jede Quelle in einer Liste von drei Systemen
    // drei Aufrufe je Vorgang, jeder mit der Zeitgrenze aus `gateway.timeout`
    // — vorbelegt 20 Sekunden (Bug #1469).
    AiBrain::shouldReceive('anbieterMitGrund')
        ->once()
        ->with(PlansForeignTasks::CAPABILITY_CREATE)
        ->andReturn(anbieterAntwort([['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']]));

    AiBrain::shouldReceive('anbieterMitGrund')->andReturn(anbieterAntwort([]));

    $eine = new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM');
    $zwei = new BrueckenSubjektquelle('peppermint-manager', 'manager', 'Manager');

    expect($eine->offers(PlansForeignTasks::CAPABILITY_CREATE))->toBeTrue()
        ->and($eine->offers(PlansForeignTasks::CAPABILITY_CREATE))->toBeTrue()
        ->and($zwei->offers(PlansForeignTasks::CAPABILITY_CREATE))->toBeFalse();
});

it('bietet ohne eingerichtete Bruecke nichts zum Planen an', function () {
    Bruecke::vergessen();

    // AI Brain selbst ist dieser Fall: Es hat das Paket, ohne die Bruecke
    // einzurichten, weil es die Nabe ist. Kein Fehlerzustand, sondern der
    // richtige Zustand.
    //
    // Hier steht ausdruecklich KEIN `shouldReceive(...)->never()`, und der
    // Grund ist der Nachweis selbst: Die Fassade liesse sich in diesem Zustand
    // nicht einmal stellen — `AiBrainManager` ist nicht aufloesbar
    // („Unresolvable dependency … array $config", der Fall, den
    // `Bruecke::eingerichtet()` im Klassenkopf beschreibt). Ein Aufruf ueber
    // die Leitung ist hier nicht vermieden, er ist unmoeglich. Wer die
    // Bruecken-Pruefung aus `offers()` entfernt, bekommt deshalb hier keinen
    // leisen `false`, sondern einen Wurf — und der Test wird rot.
    expect((new BrueckenSubjektquelle('peppermint-crm', 'crm', 'CRM'))
        ->offers(PlansForeignTasks::CAPABILITY_CREATE))->toBeFalse();
});
