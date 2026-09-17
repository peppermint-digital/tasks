<?php

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Sources\ForeignKind;
use Peppermint\Tasks\Sources\NewForeignTask;
use Peppermint\Tasks\Sources\TaskSource;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Sources\WritableTaskSource;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;
use Peppermint\Tasks\Tests\Support\ErfundeneSchreibquelle;

/*
| Quellen, in die man auch schreiben darf.
|
| Der Kalender hatte diesen Vertrag von Anfang an, die Aufgaben nur die lesende
| Haelfte. Ein Produkt, dessen Konnektor nicht schreiben kann, hat genau einen
| Weg, jemanden eine Aufgabe anlegen zu lassen: sie in ein System zu legen, das
| sich beschreiben LAESST. Das CRM hat das getan — jede Aufgabe zu einem Deal
| entstand im Manager. Nicht als Entwurf, sondern weil es keinen anderen Weg
| gab.
|
| Genau die Kopie, die diese Pakete verhindern sollen. Unsichtbar, solange
| niemand fragte, warum die eigene Aufgabentabelle des CRM leer ist.
*/

it('haelt eine lesende Quelle aus der Schreib-Liste heraus', function () {
    // Der Kern des Entwurfs: KEIN Schalter an `TaskSource`, sondern eine eigene
    // Klasse. Ein Schalter waere eine Abfrage, die jemand vergisst — und die
    // Folge waere ein Schreibweg in ein System, das nur angezeigt werden
    // sollte. Hier hat die lesende Quelle die Methoden gar nicht.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('nur-lesen'));
    $register->register(new ErfundeneSchreibquelle('darf-schreiben'));

    expect(array_keys($register->writable()))->toBe(['darf-schreiben'])
        ->and(array_keys($register->all()))->toHaveCount(2);
});

it('nimmt eine Quelle aus der Schreib-Liste, die gerade nicht schreiben darf', function () {
    // Erreichbar und trotzdem nicht beschreibbar: Das Zielsystem bietet den
    // Vorgang nicht an, oder die handelnde Person hat drueben keine Rechte.
    // Eine Oberflaeche soll den Knopf dann gar nicht erst zeigen.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSchreibquelle('gesperrt', schreibbar: false));

    expect($register->writable())->toBeEmpty()
        ->and($register->available())->toHaveCount(1,
            'Lesen soll weiter gehen — nur Schreiben ist gesperrt.');
});

it('bietet zum Schreiben nichts an, was gar nicht erreichbar ist', function () {
    // `writable()` baut auf `available()` auf, nicht auf der rohen Liste. Ein
    // Produkt ohne Zugang darf auch nicht zum Anlegen angeboten werden.
    $register = new TaskSourceRegistry;
    $register->register(new class('weg') extends ErfundeneSchreibquelle
    {
        public function isAvailable(): bool
        {
            return false;
        }
    });

    expect($register->writable())->toBeEmpty();
});

it('gibt beim Anlegen die Fassung des anderen Systems zurueck, nicht die geschickte', function () {
    // Das Zielsystem wendet Vorgaben an, leitet eine Dringlichkeit aus Faktoren
    // ab, die wir nicht haben, oder haengt seine eigene Kennung an. Angezeigt
    // werden muss SEINE Fassung.
    $quelle = new ErfundeneSchreibquelle;

    $ergebnis = $quelle->create(1, new NewForeignTask(kind: 'vorgang', title: 'Angebot schreiben'));

    expect($ergebnis->title)->toBe('Angebot schreiben (drueben)')
        ->and($ergebnis->toArray()['id'])->toBe('fremd:7',
            'Die Kennung stammt von drueben und traegt die Quelle davor.');
});

it('reicht beim Anlegen durch, was mitgegeben wurde', function () {
    // Gegenprobe zur Zusicherung oben: Dass die Antwort von drueben kommt,
    // darf nicht heissen, dass die Anfrage unterwegs verlorengeht.
    $quelle = new ErfundeneSchreibquelle;

    $quelle->create(1, new NewForeignTask(
        kind: 'crm-aktivitaet',
        title: 'Nachfassen',
        priority: 'high',
        dueDate: '2026-09-30',
        extra: ['category' => 'call'],
    ));

    $angekommen = $quelle->angelegt[0]->toArray();

    expect($angekommen)->toBe([
        'kind' => 'crm-aktivitaet',
        'title' => 'Nachfassen',
        'priority' => 'high',
        'due_date' => '2026-09-30',
        'extra' => ['category' => 'call'],
    ]);
});

it('laesst beim Anlegen weg, was niemand gesetzt hat', function () {
    // Ein leeres Feld mitzuschicken ist nicht dasselbe wie es weglassen: Das
    // Zielsystem hat Vorgaben, und `description: null` koennte drueben eine
    // vorhandene Beschreibung loeschen.
    $roh = (new NewForeignTask(kind: 'vorgang', title: 'Knapp'))->toArray();

    expect($roh)->toBe(['kind' => 'vorgang', 'title' => 'Knapp'])
        ->and($roh)->not->toHaveKey('description')
        ->and($roh)->not->toHaveKey('due_date');
});

it('schickt beim Anlegen keinen Status mit', function () {
    // Eine Aufgabe, die es noch nicht gibt, hat keinen Zustand zu setzen. Und
    // „todo" in ein System zu schicken, dessen Wort „offen" ist, waere genau
    // die Uebersetzung, die diese Pakete vermeiden.
    $roh = (new NewForeignTask(kind: 'vorgang', title: 'Neu'))->toArray();

    expect($roh)->not->toHaveKey('status');
});

it('traegt den Statuswechsel im Wort des anderen Systems', function () {
    // Unuebersetzt, wie beim Lesen. „todo" heisst dort „todo".
    $quelle = new ErfundeneSchreibquelle;

    $ergebnis = $quelle->changeStatus(1, '42', 'erledigt');

    expect($quelle->statuswechsel)->toBe([['id' => '42', 'status' => 'erledigt']])
        ->and($ergebnis->status)->toBe('erledigt');
});

it('scheitert beim Schreiben LAUT, nicht still', function () {
    // Beim Lesen verschluckt das Register einen Ausfall bewusst. Beim
    // Schreiben gilt das Gegenteil: Wer das genauso verschluckt, hinterlaesst
    // eine Aufgabe, die jemand angelegt zu haben GLAUBT und die es nirgends
    // gibt.
    $quelle = new ErfundeneSchreibquelle(faellt: true);

    expect(fn () => $quelle->create(1, new NewForeignTask(kind: 'vorgang', title: 'X')))
        ->toThrow(ForeignSourceFailed::class);

    expect(fn () => $quelle->changeStatus(1, '1', 'erledigt'))
        ->toThrow(ForeignSourceFailed::class);

    expect(fn () => $quelle->delete(1, '1'))
        ->toThrow(ForeignSourceFailed::class);
});

it('nennt in der Meldung Quelle und Vorgang', function () {
    // Damit aus „irgendwas ist schiefgegangen" eine Angabe wird, mit der sich
    // suchen laesst.
    $fehler = ForeignSourceFailed::during('changeStatus', 'crm');

    expect($fehler->sourceKey)->toBe('crm')
        ->and($fehler->operation)->toBe('changeStatus')
        ->and($fehler->getMessage())->toContain('crm')
        ->and($fehler->getMessage())->toContain('changeStatus');
});

it('gibt die Arten des anderen Systems weiter, statt sie zu raten', function () {
    // Arten sind produkteigen. Eine falsche Art legt die Aufgabe in der
    // falschen Schublade an, und es faellt erst drueben auf.
    $quelle = new ErfundeneSchreibquelle(kinds: [
        new ForeignKind('crm-aktivitaet', 'CRM-Aktivitaet', requires: ['deal']),
    ]);

    $arten = $quelle->kinds();

    expect($arten)->toHaveCount(1)
        ->and($arten[0]->toArray())->toBe([
            'key' => 'crm-aktivitaet',
            'label' => 'CRM-Aktivitaet',
            'requires' => ['deal'],
        ]);
});

it('nennt keine Arten, wenn das andere System keine herausgibt', function () {
    // Leer heisst: Der Aufrufer verlaesst sich auf die Vorgabe des Ziels. Nicht
    // raten — das ist die schlechtere Antwort.
    expect((new ErfundeneSchreibquelle)->kinds())->toBeEmpty();
});

it('ist eine schreibbare Quelle weiterhin eine lesende', function () {
    // Sonst muesste jede Stelle, die Aufgaben sammelt, beide Arten kennen.
    expect(new ErfundeneSchreibquelle)->toBeInstanceOf(WritableTaskSource::class)
        ->and(new ErfundeneSchreibquelle)->toBeInstanceOf(TaskSource::class);
});

/*
|--------------------------------------------------------------------------
| Faehigkeit und Recht sind zwei Fragen (v0.9.0)
|--------------------------------------------------------------------------
|
| Die erste Fassung dieses Vertrags verlangte anlegen, aendern UND loeschen
| zusammen — mit dem guten Argument des Kalenders: Getrennte RECHTE sind drei
| Abfragen, und die dritte vergisst jemand.
|
| Am ersten Produkt, das nicht hineinpasste, zeigte sich der Fehler: Die
| Verwaltung kann Tickets aendern und loeschen, aber nicht anlegen — ein Ticket
| entsteht, weil jemand geschrieben hat. Unter der alten Fassung war sie damit
| NIE beschreibbar, auch nicht fuers Abhaken.
|
| Das Argument des Kalenders war nicht falsch. Falsch war, dass `isWritable()`
| zwei Aufgaben hatte: fragen, was das Produkt KANN (eine Tatsache, die je
| Vorgang abweichen darf), und fragen, ob diese Person DARF (eine Regel, und da
| bleibt es bei einem Schalter).
*/

it('laesst eine Quelle aendern, die nicht anlegen kann', function () {
    // Der Fall Verwaltung, in einer Zeile.
    $quelle = new class extends ErfundeneSchreibquelle
    {
        public function supports(string $operation): bool
        {
            return $operation !== WritableTaskSource::CREATE;
        }
    };

    expect($quelle->allows(WritableTaskSource::CHANGE))->toBeTrue()
        ->and($quelle->allows(WritableTaskSource::DELETE))->toBeTrue()
        ->and($quelle->allows(WritableTaskSource::CREATE))->toBeFalse();
});

it('haelt eine Quelle ohne Schreibrecht aus JEDEM Vorgang heraus', function () {
    // Das Recht schlaegt die Faehigkeit: Wer hier nicht schreiben darf, darf
    // auch nichts davon, egal was das Produkt anbietet.
    $quelle = new ErfundeneSchreibquelle(schreibbar: false);

    foreach ([WritableTaskSource::CREATE, WritableTaskSource::CHANGE, WritableTaskSource::DELETE] as $vorgang) {
        expect($quelle->allows($vorgang))->toBeFalse();
    }
});

it('bietet eine Quelle ohne den Vorgang nicht zum Anlegen an', function () {
    $register = new TaskSourceRegistry;

    $register->register(new ErfundeneSchreibquelle('kann-alles'));
    $register->register(new class('nur-aendern') extends ErfundeneSchreibquelle
    {
        public function supports(string $operation): bool
        {
            return $operation !== WritableTaskSource::CREATE;
        }
    });

    expect(array_keys($register->writableFor(WritableTaskSource::CREATE)))->toBe(['kann-alles'])
        // Zum Aendern sind es BEIDE — sonst waere die Verwaltung wieder
        // draussen, und genau das war der Fehler.
        ->and(array_keys($register->writableFor(WritableTaskSource::CHANGE)))
        ->toBe(['kann-alles', 'nur-aendern'])
        // Und `writable()` bleibt die weitere Frage: „darf hier ueberhaupt
        // geschrieben werden".
        ->and(array_keys($register->writable()))->toBe(['kann-alles', 'nur-aendern']);
});

it('bietet ohne Schreibrecht auch fuer einen einzelnen Vorgang nichts an', function () {
    // `writableFor()` baut auf `writable()` auf — sonst umginge es das Recht.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneSchreibquelle('gesperrt', schreibbar: false));

    expect($register->writableFor(WritableTaskSource::CHANGE))->toBeEmpty();
});

it('bietet alles an, wer nichts einschraenkt', function () {
    // Der Rueckfall muss halten: Eine vorhandene Quelle soll sich nicht
    // aendern muessen. Die Erweiterung ist additiv.
    $quelle = new ErfundeneSchreibquelle;

    foreach ([WritableTaskSource::CREATE, WritableTaskSource::CHANGE, WritableTaskSource::DELETE] as $vorgang) {
        expect($quelle->supports($vorgang))->toBeTrue()
            ->and($quelle->allows($vorgang))->toBeTrue();
    }
});
