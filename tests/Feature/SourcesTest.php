<?php

use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\TaskSource;
use Peppermint\Tasks\Sources\TaskSourceRegistry;
use Peppermint\Tasks\Tests\Support\ErfundeneQuelle;

function fremdeAufgabe(string $titel = 'Fremd'): ForeignTask
{
    return new ForeignTask(sourceKey: 'fremd', id: 42, title: $titel, status: 'todo', urgency: 9.5);
}

it('sammelt die Aufgaben aller angemeldeten Quellen', function () {
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('fremd', [fremdeAufgabe('A'), fremdeAufgabe('B')]));

    expect($register->collect(1))->toHaveCount(2);
});

it('fragt eine abgeschaltete Quelle gar nicht erst', function () {
    // Nicht bloss ausblenden: Eine fremde Quelle bedeutet einen Aufruf an ein
    // anderes System, und der soll unterbleiben, wenn niemand das Ergebnis
    // sehen will.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('fremd', [fremdeAufgabe()], faellt: true));

    // Waere sie gefragt worden, haette sie geworfen.
    expect($register->collect(1, skip: ['fremd']))->toBeEmpty();
});

it('laesst eine unerreichbare Quelle die Liste nicht mitreissen', function () {
    // Eine Seite, die nichts zeigt, weil der Server eines anderen Systems
    // steht, ist schlimmer als eine Seite ohne ein Filter-Chip.
    $register = new TaskSourceRegistry;
    $register->register(new ErfundeneQuelle('kaputt', faellt: true));
    $register->register(new ErfundeneQuelle('heil', [fremdeAufgabe('Kommt durch')]));

    $ergebnis = $register->collect(1);

    expect($ergebnis)->toHaveCount(1)
        ->and($ergebnis[0]->title)->toBe('Kommt durch');
});

it('stellt der fremden Kennung die Quelle voran', function () {
    // Sonst liesse sich eine fremde Aufgabe fuer eine eigene halten — und ein
    // Griff daneben oeffnet die falsche oder aendert sie gar.
    $daten = fremdeAufgabe()->toArray();

    expect($daten['id'])->toBe('fremd:42')
        ->and($daten['foreign_id'])->toBe(42)
        ->and($daten['external'])->toBeTrue();
});

it('traegt den Dringlichkeitswert, aber nicht die Aufschluesselung', function () {
    // Die Zahl laesst sich vergleichen. Die Aufschluesselung nicht: Ihre
    // Faktoren beziehen sich auf Dinge, die dieses System nicht hat —
    // Zeitbloecke, Blocker, Kommentare des anderen Systems.
    $daten = fremdeAufgabe()->toArray();

    expect($daten['urgency'])->toBe(9.5)
        ->and($daten)->not->toHaveKey('factors');
});

it('laesst die Art des anderen Systems mitreisen', function () {
    // Der Sinn EINER gemeinsamen Liste ist, sie filtern zu koennen: „ich habe
    // noch drei Verwaltungssachen offen". Ohne die Art kann die fremde Haelfte
    // das nicht beantworten — und ein Filter, der stillschweigend nur die
    // Haelfte erfasst, ist schlechter als keiner.
    $fremd = new ForeignTask(
        sourceKey: 'verwaltung',
        id: 42,
        title: 'Rechnung schreiben',
        kind: 'ticket',
        kindLabel: 'Ticket',
    );

    expect($fremd->toArray()['kind'])->toBe('ticket')
        ->and($fremd->toArray()['kind_label'])->toBe('Ticket');
});

it('faellt beim Beschriften auf den Schluessel zurueck', function () {
    // Ein System, das seine Arten nicht benennt, soll trotzdem filterbar sein.
    $fremd = new ForeignTask(
        sourceKey: 'crm',
        id: 7,
        title: 'Nachfassen',
        kind: 'wiedervorlage',
    );

    expect($fremd->toArray()['kind_label'])->toBe('wiedervorlage');
});

it('fragt eine Quelle gar nicht erst, die nicht verfuegbar ist', function () {
    // Der autark-Riegel: Wer keinen Zugang zum CRM hat, hat keine CRM-Aufgaben
    // — und das ist kein Fehlerzustand, den man meldet, sondern die richtige
    // Antwort. „Nicht fragen" statt „hinterher ausblenden", weil die Frage ein
    // Aufruf in ein anderes System ist.
    $abgeschaltet = new class extends TaskSource
    {
        public bool $gefragt = false;

        public function key(): string
        {
            return 'abgeschaltet';
        }

        public function label(): string
        {
            return 'Abgeschaltet';
        }

        public function isAvailable(): bool
        {
            return false;
        }

        public function tasks(int $userId): array
        {
            $this->gefragt = true;

            return [];
        }
    };

    $register = app(TaskSourceRegistry::class);
    $register->register($abgeschaltet);

    $register->collect(1);

    expect($abgeschaltet->gefragt)->toBeFalse()
        ->and($register->available())->not->toHaveKey('abgeschaltet');
});

it('stellt der fremden ART die Quelle voran', function () {
    // Arten sind produkteigen und ihre Schluessel NICHT systemuebergreifend
    // eindeutig: Brain, der Manager und das CRM nennen ihre Standard-Art alle
    // drei `vorgang`. Eine zusammengefasste Liste, die ihren Filter nach `kind`
    // schluesselt, laesst sie zu einem Chip kollabieren — dieselbe Verwechslung,
    // gegen die die Kennung schon geschuetzt ist.
    $aufgabe = new ForeignTask(sourceKey: 'crm', id: 1, title: 'X', kind: 'vorgang', kindLabel: 'CRM-Aufgabe');

    $roh = $aufgabe->toArray();

    expect($roh['kind_key'])->toBe('crm:vorgang')
        // Das rohe Wort bleibt daneben stehen — es ist die Vokabel des anderen
        // Systems, und irgendetwas muss sie noch tragen.
        ->and($roh['kind'])->toBe('vorgang')
        ->and($roh['kind_label'])->toBe('CRM-Aufgabe');
});

it('macht aus zwei gleichnamigen Arten zwei Schluessel', function () {
    // Der Fall aus Bug #876 in einer Zeile.
    $ausCrm = (new ForeignTask(sourceKey: 'crm', id: 1, title: 'X', kind: 'vorgang'))->toArray();
    $ausManager = (new ForeignTask(sourceKey: 'manager', id: 1, title: 'X', kind: 'vorgang'))->toArray();

    expect($ausCrm['kind_key'])->not->toBe($ausManager['kind_key']);
});

it('erfindet keinen Art-Schluessel, wenn das Produkt keine Art nennt', function () {
    // Sonst stuende in der Filterleiste ein Chip „crm:" ohne Bedeutung.
    $roh = (new ForeignTask(sourceKey: 'crm', id: 1, title: 'X'))->toArray();

    expect($roh['kind_key'])->toBeNull()
        ->and($roh['kind'])->toBeNull();
});
