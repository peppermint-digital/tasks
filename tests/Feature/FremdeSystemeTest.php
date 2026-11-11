<?php

use Illuminate\Support\Facades\Log;
use Peppermint\Tasks\Sources\CallableRemoteTaskSystem;
use Peppermint\Tasks\Sources\RemoteResult;
use Peppermint\Tasks\Sources\RemoteTaskSource;
use Peppermint\Tasks\Sources\SourceFailure;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;
use Peppermint\Tasks\Tests\Support\ErfundenesSystem;

/*
| Die Aufgaben eines anderen Systems — geholt, nie uebernommen.
|
| Die Mechanik steht im Paket (fragen, uebersetzen, Ausfall unterscheiden,
| Person aufloesen), der WEG nach draussen beim Gastgeber: Die Nabe ruft ihre
| angebundenen Produkte ueber den eigenen Gateway, ein Produkt ueber die
| Bridge. Dieselbe Teilung wie beim Kalender, und aus demselben Grund — steckte
| der Weg in der Quelle, gaebe es die Quelle je Anwendung einmal.
|
| Drei davon gab es schon, eine je Produkt.
*/

function zeile(array $felder = []): array
{
    return array_merge([
        'id' => 1364,
        'title' => 'Rechnung schreiben',
        'status' => 'todo',
        'priority' => 'high',
        'kind' => 'vorgang',
        'kind_label' => 'Aufgabe',
        'urgency_score' => 9.5,
        'url' => 'https://pm.example.com/tasks/1364',
    ], $felder);
}

it('erscheint gar nicht als Quelle, wenn das System seine Aufgaben nicht herausgibt', function () {
    // Der autark-Riegel, gemessen statt konfiguriert: Wer `tasks.list` nicht
    // anbietet, ist keine Quelle — kein leerer Abschnitt, kein totes
    // Filter-Chip, nichts zum Auswaehlen in einer Maske. Und verliert ein
    // Produkt das Werkzeug spaeter, verschwindet die Quelle von selbst, ohne
    // dass jemand daran denken muss.
    $ohne = new RemoteTaskSource(new ErfundenesSystem('crm', faehigkeiten: []));
    $mit = new RemoteTaskSource(new ErfundenesSystem('manager'));

    $register = new TaskSourceRegistry;
    $register->register($ohne);
    $register->register($mit);

    expect($ohne->isAvailable())->toBeFalse()
        ->and(array_keys($register->available()))->toBe(['manager'])
        // In `all()` steht sie weiter — angemeldet ist sie, nur nicht nutzbar.
        ->and($register->all())->toHaveCount(2);
});

it('fragt gar nicht, wenn die Person drueben nicht benennbar ist', function () {
    // Fail-closed, und zwar BEVOR der Aufruf hinausgeht. Die Lehre aus Bug #657
    // im Kalender: Ein Argument, das bei fehlender Identitaet verfaellt, macht
    // die Antwort WEITER statt enger — dort war es der ganze Team-Kalender,
    // hier waeren es die Aufgaben fremder Leute in der Liste des Angemeldeten.
    $system = new ErfundenesSystem(antworten: [
        'tasks.list' => RemoteResult::ok([zeile()]),
    ], kennung: null);

    $quelle = new RemoteTaskSource($system);

    expect($quelle->tasks(1))->toBeEmpty()
        ->and($system->aufrufe)->toBeEmpty('Es darf nicht einmal gefragt worden sein.');
});

it('schreibt eine nicht benennbare Person ins Protokoll, mit System und Kennung', function () {
    // Kein Ausfall — die Abfrage hat nicht stattgefunden, weil es nichts zu
    // fragen gab, und wer drueben kein Konto hat, hat drueben keine Aufgaben.
    //
    // Aber genau so sieht auch ein FEHLENDES MAPPING aus: Ist die Zuordnung
    // nicht gepflegt, steht „keine offenen Aufgaben" bei einem System, das
    // welche hat, und auf der Seite ist nichts, woran man es merkt. Deshalb
    // steht es im Protokoll — mit Systemschluessel und Nutzerkennung, damit es
    // diagnostizierbar ist, ohne dass die Maske einen vierten Zustand bekommt.
    Log::spy();

    $quelle = new RemoteTaskSource(new ErfundenesSystem('crm', kennung: null));

    expect($quelle->tasks(42))->toBeEmpty();

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $ereignis, array $daten) => $ereignis === 'tasks.remote_identity_missing'
            && $daten['source'] === 'crm'
            && $daten['user_id'] === 42,
    )->once();
});

it('haelt ein System ohne benennbare Person trotzdem fuer beantwortet', function () {
    // Die Kehrseite derselben Entscheidung: Es ist kein Ausfall, also steht
    // das System in `ok` und nicht in `failures`. Eine Maske, die hier „CRM
    // nicht erreichbar" zeigte, behauptete eine Stoerung, wo ein Mensch
    // einfach kein Konto hat.
    Log::spy();

    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new ErfundenesSystem('crm', kennung: null)));

    $ernte = $register->harvest(1);

    expect($ernte->ok)->toBe(['crm'])
        ->and($ernte->failures)->toBe([])
        ->and($ernte->empty())->toBeTrue();
});

it('unterscheidet ein nicht erreichbares System von einem ohne Aufgaben', function () {
    // Der Grund, warum es `harvest()` neben `collect()` gibt.
    //
    // Durch `collect()` sehen die beiden Antworten gleich aus — beide eine
    // leere Liste. „Nichts offen" darf die Maske anzeigen; „war nicht
    // erreichbar" als „nichts offen" anzuzeigen ist eine Luege, auf die jemand
    // seinen Tag plant. Ein Protokolleintrag hilft dabei nicht: Er erreicht die
    // Person vor dem Bildschirm nicht.
    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new ErfundenesSystem('leer')));
    $register->register(new RemoteTaskSource(new ErfundenesSystem('kaputt', antworten: [
        'tasks.list' => RemoteResult::failed('Zeitueberschreitung', 'unreachable'),
    ])));

    $ernte = $register->harvest(1);

    expect($ernte->tasks)->toBeEmpty()
        ->and($ernte->ok)->toBe(['leer'])
        ->and($ernte->failures)->toHaveKey('kaputt')
        // Der Text der Gegenstelle reist mit: „nicht erreichbar:
        // Zeitueberschreitung" sagt jemandem, was zu tun ist, „Fehler" nicht.
        ->and($ernte->failures['kaputt']->message)->toContain('Zeitueberschreitung')
        // Und seit der Zusammenlegung (#9809) auch der GRUND, typisiert. Das
        // konnte keine der beiden Fassungen allein: Die Personen-Seite hatte
        // nur den Text, und `RemoteResult` kannte die Klasse bereits — sie fiel
        // im Wurf auf dem Weg nach oben weg. Eine Maske, die „keine Freigabe"
        // von „nicht erreichbar" unterscheiden soll, haette sonst den Satz
        // zerlegen muessen, und eine Umformulierung drueben kostet sie die
        // Unterscheidung wieder.
        ->and($ernte->failures['kaputt']->reason)->toBe(SourceFailure::UNREACHABLE)
        ->and($ernte->failures['kaputt']->mightPassByItself())->toBeTrue()
        ->and($ernte->complete())->toBeFalse()
        ->and($ernte->empty())->toBeFalse('Leer UND vollstaendig waere die Luege.');
});

it('heisst leer erst dann wirklich leer, wenn niemand ausgefallen ist', function () {
    // Der Satz, auf den die naechste Maske sich verlassen darf.
    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new ErfundenesSystem('leer')));

    $ernte = $register->harvest(1);

    expect($ernte->empty())->toBeTrue()
        ->and($ernte->complete())->toBeTrue();
});

it('laesst eine unerreichbare Quelle die Liste nicht mitreissen', function () {
    // Lesen bleibt leise: Eine Seite, die nichts zeigt, weil der Server eines
    // anderen Systems steht, ist schlimmer als eine Liste ohne ein Chip. Der
    // Ausfall steht jetzt zusaetzlich in der Antwort — nicht statt dessen.
    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new ErfundenesSystem('kaputt', antworten: [
        'tasks.list' => RemoteResult::failed('Zeitueberschreitung'),
    ])));
    $register->register(new RemoteTaskSource(new ErfundenesSystem('heil', antworten: [
        'tasks.list' => RemoteResult::ok([zeile(['title' => 'Kommt durch'])]),
    ])));

    $ernte = $register->harvest(1);

    expect($ernte->tasks)->toHaveCount(1)
        ->and($ernte->tasks[0]->title)->toBe('Kommt durch')
        ->and(array_keys($ernte->failures))->toBe(['kaputt'])
        // Ohne Fehlerklasse von drueben bleibt der Grund leer, statt geraten
        // zu werden: Wer hier `unreachable` einsetzte, versprach der Maske
        // eine Unterscheidung, die niemand getroffen hat.
        ->and($ernte->failures['kaputt']->reason)->toBeNull();
});

it('laesst collect() unveraendert, obwohl es jetzt harvest() fragt', function () {
    // `collect()` ruft die Nabe. Dass es seinen Rumpf an `harvest()` abgegeben
    // hat, darf an seiner Antwort nichts aendern — sonst waere die Umstellung
    // eine Verhaltensaenderung in vier Produkten.
    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new ErfundenesSystem('heil', antworten: [
        'tasks.list' => RemoteResult::ok([zeile(), zeile(['id' => 1365])]),
    ])));
    $register->register(new ErfundeneQuelle('kaputt', faellt: true));

    expect($register->collect(1))->toHaveCount(2)
        ->and($register->collect(1, skip: ['heil']))->toBeEmpty();
});

it('reicht die uebrigen Felder des anderen Systems durch', function () {
    // Wer Felder aufzaehlt, statt durchzureichen, bricht bei jedem neuen Feld
    // erneut — und zwar LEISE: Das Werkzeug meldet Erfolg, die Oberflaeche
    // zeigt es nicht, gesucht wird am falschen Ende. Bis #5830 standen hier
    // drei Felder namentlich, waehrend das CRM schon `category` und die
    // Verwaltung `ticket_number` mitschickte.
    $quelle = new RemoteTaskSource(new ErfundenesSystem(antworten: [
        'tasks.list' => RemoteResult::ok([zeile([
            'ticket_number' => 'VW-40',
            'customer' => 'Musterfirma',
            'category' => 'Nachfassen',
            'status_label' => 'Offen',
            'project' => ['id' => 9, 'name' => 'Webseite'],
            'leer' => null,
        ])]),
    ]));

    $aufgabe = $quelle->tasks(1)[0];

    expect($aufgabe->extra)->toMatchArray([
        'ticket_number' => 'VW-40',
        'customer' => 'Musterfirma',
        'category' => 'Nachfassen',
        // Nur der Manager schickt es — „wenn da, dann mit", ohne ein Feld in
        // der Drahtform, das zwei von drei Produkten nicht fuellen.
        'status_label' => 'Offen',
        // Verschachteltes landete als „[object Object]" in der Zeile: Es
        // interessiert der Name, nicht die Zeile dahinter.
        'project' => 'Webseite',
    ])->and($aufgabe->extra)->not->toHaveKey('leer');
});

it('laesst ein fremdes Feld kein eigenes ueberschreiben', function () {
    // `ForeignTask` wirft kollidierende Schluessel NICHT weg — anders als der
    // `ExternalEvent` des Kalenders. Also duerfen sie gar nicht hineinkommen,
    // sonst stuende dasselbe zweimal in einer Zeile, einmal als Kernfeld und
    // einmal als angebliche Zusatzinformation, und beide koennten verschieden
    // sein.
    $quelle = new RemoteTaskSource(new ErfundenesSystem(antworten: [
        'tasks.list' => RemoteResult::ok([zeile()]),
    ]));

    $aufgabe = $quelle->tasks(1)[0];

    expect($aufgabe->title)->toBe('Rechnung schreiben')
        ->and($aufgabe->urgency)->toBe(9.5)
        ->and($aufgabe->extra)->toBe([], 'Kein Kernfeld darf als Restfeld zurueckkommen.');
});

it('nimmt die Adresse nur vom Produkt selbst', function () {
    // Der Rueckfall „Wurzeladresse plus /tasks/{id}" war fuer den Manager
    // richtig und fuer die Verwaltung falsch (dort sind es `/tickets/{id}`).
    // Aufgefallen ist das nur zufaellig. Ein Link auf eine 404 sieht aus wie
    // ein Weg, der funktioniert — ohne Link zeigt die Oberflaeche Text, und
    // das ist die ehrlichere Antwort.
    $quelle = new RemoteTaskSource(new ErfundenesSystem(antworten: [
        'tasks.list' => RemoteResult::ok([
            zeile(['id' => 40, 'url' => 'https://vw.example.com/tickets/40']),
            zeile(['id' => 41, 'url' => '']),
        ]),
    ]));

    [$mit, $ohne] = $quelle->tasks(1);

    expect($mit->url)->toBe('https://vw.example.com/tickets/40')
        ->and($ohne->url)->toBeNull();
});

it('liest die Zeilen auch aus einem Umschlag', function () {
    // Der Vertrag ist eine BLANKE Liste. Ein Produkt, das
    // `{count: 1, data: [...]}` schickt, ergab vorher Vorgaenge ohne Titel —
    // je einen pro Schluessel des Umschlags. Kein Fehler, keine Meldung, nur
    // Unsinn in der Liste. Die strenge Lesart bestraft den Leser fuer den
    // Fehler des Schreibers.
    $quelle = new RemoteTaskSource(new ErfundenesSystem(antworten: [
        'tasks.list' => RemoteResult::ok(['count' => 1, 'data' => [zeile()]]),
    ]));

    $aufgaben = $quelle->tasks(1);

    expect($aufgaben)->toHaveCount(1)
        ->and($aufgaben[0]->title)->toBe('Rechnung schreiben');
});

it('laesst eine Zeichenkette zwischen den Zeilen den Rest nicht verderben', function () {
    // Ein fremdes System soll die Liste nicht dadurch leeren koennen, dass es
    // einmal etwas Unerwartetes mitschickt.
    $quelle = new RemoteTaskSource(new ErfundenesSystem(antworten: [
        'tasks.list' => RemoteResult::ok([zeile(), 'kaputt', zeile(['id' => 2])]),
    ]));

    expect($quelle->tasks(1))->toHaveCount(2);
});

it('nennt die Zeilenzahl und fragt nur nach Offenem', function () {
    // Ohne das Argument antwortet jedes der drei Produkte mit 50 — und eine
    // Liste, die bei 50 endet, ist keine lange Liste, sondern eine falsche:
    // Nichts sagt, dass sie abgeschnitten ist.
    //
    // `active_only` gehoert zum gehobenen Deckel: Ohne Grenze kamen 50 Zeilen,
    // mit Grenze 470 — davon 438 abgeschlossen. Nur den Deckel zu heben haette
    // die Ansicht, in der man nachsieht, was als Naechstes zu tun ist, mit
    // Vergangenheit geflutet.
    $system = new ErfundenesSystem;

    (new RemoteTaskSource($system, rows: 500))->tasks(1);

    expect($system->aufrufe[0]['vorgang'])->toBe('tasks.list')
        ->and($system->aufrufe[0]['argumente'])->toBe(['limit' => 500, 'active_only' => true]);
});

it('holt die Arten des anderen Systems, wenn es sie anbietet', function () {
    // Arten sind produkteigen — dieses Paket kennt weder `ticket` noch
    // `routine`. Raten waere die schlechtere Antwort: Eine falsche Art legt die
    // Aufgabe in die falsche Schublade, und das faellt erst drueben auf.
    $quelle = new RemoteTaskSource(new ErfundenesSystem(
        faehigkeiten: ['tasks.list', 'tasks.kinds.list'],
        antworten: [
            'tasks.kinds.list' => RemoteResult::ok([
                ['key' => 'vorgang', 'label' => 'Aufgabe'],
                ['key' => 'routine', 'label' => 'Routine', 'requires' => ['assigned_to']],
                // Ohne Schluessel keine Art — ein Chip ohne Bedeutung ist
                // schlechter als keiner.
                ['label' => 'Namenlos'],
            ]),
        ],
    ));

    $arten = $quelle->kinds();

    expect($arten)->toHaveCount(2)
        ->and($arten[0]->key)->toBe('vorgang')
        ->and($arten[0]->label)->toBe('Aufgabe')
        // Die Pflichtfelder reisen mit, damit eine Maske sie vorher erfragt
        // statt den Fehler nach dem Senden zu zeigen.
        ->and($arten[1]->requires)->toBe(['assigned_to']);
});

it('fragt die Arten nicht, wenn das System sie nicht anbietet', function () {
    // Und bleibt dabei leise: Eine fehlende Artenliste kostet eine Maske ihre
    // Vorauswahl, der Aufrufer faellt auf die Vorgabe des Zielsystems zurueck.
    // Dafuer die ganze Quelle fallen zu lassen waere der groessere Schaden.
    $system = new ErfundenesSystem(faehigkeiten: ['tasks.list']);
    $quelle = new RemoteTaskSource($system);

    expect($quelle->kinds())->toBeEmpty()
        ->and($system->aufrufe)->toBeEmpty();
});

it('laesst einen Ausfall bei den Arten die Quelle nicht fallen', function () {
    $quelle = new RemoteTaskSource(new ErfundenesSystem(
        faehigkeiten: ['tasks.list', 'tasks.kinds.list'],
        antworten: ['tasks.kinds.list' => RemoteResult::failed('Zeitueberschreitung')],
    ));

    expect($quelle->kinds())->toBeEmpty()
        ->and($quelle->isAvailable())->toBeTrue();
});

it('tut die Kurzform mit Closure dasselbe wie eine eigene Klasse', function () {
    // {@see CallableRemoteTaskSystem} ist die Abkuerzung fuer den Regelfall,
    // nicht die einzige Tuer. Beim ersten Abnehmer sah die Erfuellung des
    // Vertrags produktspezifisch aus, beim zweiten war sie zeichengleich — also
    // wandert sie ins Paket, bevor die zweite Kopie entsteht und ab dem ersten
    // Fix auseinanderlaeuft.
    $eigeneKlasse = new RemoteTaskSource(new ErfundenesSystem('manager', antworten: [
        'tasks.list' => RemoteResult::ok([zeile()]),
    ]));

    $kurzform = new RemoteTaskSource(new CallableRemoteTaskSystem(
        key: 'manager',
        label: 'Fremdes System',
        capabilities: ['tasks.list'],
        transport: fn (string $vorgang, array $daten) => RemoteResult::ok([zeile()]),
        identify: fn (int $userId) => 'jemand@example.com',
    ));

    expect($kurzform->key())->toBe($eigeneKlasse->key())
        ->and($kurzform->label())->toBe($eigeneKlasse->label())
        ->and($kurzform->isAvailable())->toBe($eigeneKlasse->isAvailable())
        ->and($kurzform->tasks(1)[0]->toArray())->toBe($eigeneKlasse->tasks(1)[0]->toArray());
});

it('macht aus einer unbenennbaren Person auch in der Kurzform eine leere Liste', function () {
    // Eine leere Zeichenkette ist keine Identitaet: Ein Transport, der sie
    // mitschickte, fragte in niemandes Namen — und das Zielsystem antwortet
    // dann mit seiner Vorgabe, also der WEITEREN Antwort.
    $kurzform = new CallableRemoteTaskSystem(
        key: 'manager',
        label: 'Manager',
        capabilities: ['tasks.list'],
        transport: fn () => RemoteResult::ok([zeile()]),
        identify: fn (int $userId) => '',
    );

    expect($kurzform->identify(1))->toBeNull()
        ->and((new RemoteTaskSource($kurzform))->tasks(1))->toBeEmpty();
});

it('macht aus einem kaputten Aufrufweg einen Fehlschlag und keinen Absturz', function () {
    // Ein Transport, der kein RemoteResult liefert, ist ein Fehler im
    // Aufrufer — aber er darf die Aufgabenliste nicht mitnehmen. Also derselbe
    // Weg wie bei einem nicht erreichbaren System: ein Fehlschlag mit Grund,
    // kein Absturz mitten im Zeichnen.
    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new CallableRemoteTaskSystem(
        key: 'murks',
        label: 'Murks',
        capabilities: ['tasks.list'],
        transport: fn () => ['ok' => true],
        identify: fn (int $userId) => 'jemand@example.com',
    )));

    $ernte = $register->harvest(1);

    expect($ernte->tasks)->toBeEmpty()
        ->and($ernte->failures)->toHaveKey('murks')
        // Und der Grund, den `CallableRemoteTaskSystem` dafuer gesetzt hat,
        // kommt oben an: `tool_failed`. Das ist der ganze Weg der
        // Zusammenlegung an einem Stueck — Grund im Aufrufweg gesetzt, als
        // Wurf durch die Quelle getragen, in der Ernte typisiert hinterlegt.
        // Vor dem 11.11.2026 blieb davon nur ein Satz uebrig.
        ->and($ernte->failures['murks']->reason)->toBe(SourceFailure::TOOL_FAILED);
});

it('nimmt die Fehlerklasse der Gegenstelle auf, auch ohne Text', function () {
    // Eine Gegenstelle, die nur die Fehlerklasse nennt, soll nicht zu einer
    // Fehlermeldung ohne Text fuehren — „nicht erreichbar: " ist schlimmer als
    // „nicht erreichbar: unreachable".
    $ergebnis = RemoteResult::fromArray(['ok' => false, 'error' => 'unreachable']);

    expect($ergebnis->ok)->toBeFalse()
        ->and($ergebnis->message)->toBe('unreachable')
        ->and($ergebnis->error)->toBe('unreachable');
});

it('liest ein ok, das kein echtes true ist, als Fehlschlag', function () {
    // Die strenge Lesart, an der einen Stelle, die beide Fragen lesen (#9809).
    //
    // Der Fall, um den es geht, ist nicht erfunden: `(bool) 'false'` ist
    // `true`. Lose gelesen gilt so eine Antwort als Erfolg, `data` ist dann
    // keine Liste, `rows()` ist leer — und heraus kommt eine leere
    // Aufgabenliste, die sich als vollstaendige Auskunft ausgibt. „Keine
    // offenen Aufgaben" bei einem System, das welche hat, und auf der Seite
    // ist nichts, woran man es merkt.
    //
    // Alles Truthy-aber-kein-Bool ist eine KAPUTTE Antwort, und eine kaputte
    // Antwort ist kein Erfolg. Wer das wieder lockert, will diese leere Liste.
    expect(RemoteResult::fromArray(['ok' => 'false'])->ok)->toBeFalse()
        ->and(RemoteResult::fromArray(['ok' => 1])->ok)->toBeFalse()
        ->and(RemoteResult::fromArray(['ok' => '1'])->ok)->toBeFalse()
        ->and(RemoteResult::fromArray(['ok' => 'yes'])->ok)->toBeFalse()
        ->and(RemoteResult::fromArray([])->ok)->toBeFalse()
        // Gegenprobe, damit der Test nicht bloss „alles ist falsch" prueft.
        ->and(RemoteResult::fromArray(['ok' => true])->ok)->toBeTrue();
});

it('macht aus einer kaputten Antwort einen Ausfall und keine leere Liste', function () {
    // Dasselbe eine Ebene hoeher, weil dort der Schaden entstehen wuerde: Die
    // Quelle muss werfen, das Register muss es als Ausfall fuehren. Ginge die
    // Antwort als Erfolg durch, stuende das System in `ok` — und `empty()`
    // saegte „wirklich nichts offen".
    $register = new TaskSourceRegistry;
    $register->register(new RemoteTaskSource(new CallableRemoteTaskSystem(
        key: 'crm',
        label: 'CRM',
        capabilities: ['tasks.list'],
        transport: fn () => RemoteResult::fromArray(['ok' => 'false']),
        identify: fn (int $userId) => 'jemand@example.com',
    )));

    $ernte = $register->harvest(1);

    expect($ernte->ok)->toBe([])
        ->and($ernte->failures)->toHaveKey('crm')
        ->and($ernte->empty())->toBeFalse('Leer UND vollstaendig waere hier die Luege.');
});

it('liest einen Erfolg ohne Daten nicht als Fehlschlag', function () {
    // `ok` ist nicht aus `data` ableitbar: Ein Vorgang kann gelingen und nichts
    // zurueckgeben. Wer auf `data !== null` prueft, haelt das eine fuer das
    // andere.
    $ergebnis = RemoteResult::fromArray(['ok' => true]);

    expect($ergebnis->ok)->toBeTrue()
        ->and($ergebnis->rows())->toBeEmpty();
});

it('nimmt die Farbe aus der Anwendung und nicht aus dem Aufrufweg', function () {
    // Farbe ist reine Darstellung und gehoert der Oberflaeche. Der Test haelt
    // vor allem den SCHLUESSEL fest: Er steht jetzt als dokumentierter
    // Platzhalter in config/tasks.php, und ein Umbenennen dort muesste hier
    // auffallen — sonst liest die Quelle still ins Leere, und niemand sucht
    // bei einer fehlenden Farbe nach einem Tippfehler in der Config.
    config()->set('tasks.source_colours.verwaltung', '#f59e0b');

    $mitFarbe = new RemoteTaskSource(new ErfundenesSystem('verwaltung'));
    $ohneFarbe = new RemoteTaskSource(new ErfundenesSystem('manager'));

    expect($mitFarbe->colour())->toBe('#f59e0b')
        // Kein Eintrag heisst null: Die Anzeige faellt auf ihre eigene Vorgabe
        // zurueck, statt dass das Paket eine Palette mitbringt, die jeder
        // Abnehmer dann ueberschreiben muss.
        ->and($ohneFarbe->colour())->toBeNull();
});
