<?php

use Carbon\CarbonImmutable;
use Peppermint\Tasks\Calendar\TaskDeadlineSource;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Tests\Fixtures\EigeneAufgabe;
use Peppermint\Tasks\Tests\Support\Fertig;
use Peppermint\Tasks\Tests\Support\Offen;

/**
 * Fristen erscheinen im Kalender, ohne dass jemand Termine anlegt (#7786).
 *
 * Eine Frist ist keine Verabredung, sondern eine Eigenschaft der Aufgabe. Als
 * Termin gespeichert gäbe es sie zweimal — und eine der beiden Richtungen des
 * Nachziehens wird irgendwann vergessen.
 */
beforeEach(function () {
    // Ohne registrierte Status findet `scopeOpen()` nichts — das Paket kennt
    // kein eingebautes Vokabular, jede Anwendung bringt ihres mit. Beim ersten
    // Lauf dieses Tests war genau das der Grund, warum die Quelle leer blieb.
    config()->set('tasks.statuses', [Offen::class, Fertig::class]);

    $this->quelle = new TaskDeadlineSource;
    $this->von = CarbonImmutable::parse('2026-11-01');
    $this->bis = CarbonImmutable::parse('2026-11-30');
});

it('zeigt die Frist einer offenen Aufgabe', function () {
    $aufgabe = Task::create([
        'title' => 'Angebot abgeben',
        'status' => 'offen',
        'kind' => 'general',
        'due_date' => '2026-11-03 14:37:00',
        'assigned_to' => 7,
    ]);

    $eintraege = $this->quelle->events(7, $this->von, $this->bis);

    expect($eintraege)->toHaveCount(1);

    $eintrag = $eintraege[0]->toArray();

    expect($eintrag['title'])->toBe('Angebot abgeben')
        // Ganztags, obwohl 14:37 in der Spalte steht: Die Fristenlogik des
        // Pakets rechnet durchgehend in Tagen. Eine Frist auf die Stunde genau
        // zu zeichnen verspräche eine Genauigkeit, die es nicht gibt — und
        // verdeckte im Tagesraster die Termine, die wirklich dann stattfinden.
        ->and($eintrag['all_day'])->toBeTrue()
        ->and($eintrag['id'])->toBe('task-deadlines:'.$aufgabe->getKey());
});

it('liefert etwas, das gar nicht gespeichert werden KANN', function () {
    // Der Punkt der ganzen Übung: Die Aufgabe bleibt die einzige Wahrheit.
    //
    // Nachgewiesen über den Typ, nicht über eine leere Tabelle: Die
    // Kalendertabellen gibt es in der Testumgebung dieses Pakets gar nicht —
    // es hängt am Kalender nur über `suggest`. Ein Test, der sie zählt, prüfte
    // hier nichts und wäre trotzdem grün.
    //
    // `ExternalEvent` ist die Form, in der der Kalender Fremdes ZEIGT. Sie hat
    // kein `save()`, keine Verbindung, keinen Tabellennamen.
    Task::create([
        'title' => 'Ohne Nebenwirkung',
        'status' => 'offen',
        'kind' => 'general',
        'due_date' => '2026-11-05',
        'assigned_to' => 7,
    ]);

    $eintraege = $this->quelle->events(7, $this->von, $this->bis);

    expect($eintraege[0])->toBeInstanceOf(\Peppermint\Calendar\Sources\ExternalEvent::class)
        ->and($eintraege[0])->not->toBeInstanceOf(\Illuminate\Database\Eloquent\Model::class)
        // Und die Kennung trägt den Quellenschlüssel: Sie kann nie mit der
        // eines echten Termins verwechselt werden.
        ->and($eintraege[0]->toArray()['id'])->toStartWith('task-deadlines:');
});

it('laesst erledigte Aufgaben weg', function () {
    // Eine erledigte Aufgabe hat keine Frist mehr, sie hat ein Datum. Sie
    // weiter zu zeigen füllte den Kalender mit Erledigtem.
    Task::create([
        'title' => 'Schon erledigt',
        'status' => 'fertig',
        'kind' => 'general',
        'due_date' => '2026-11-04',
        'assigned_to' => 7,
    ]);

    expect($this->quelle->events(7, $this->von, $this->bis))->toBeEmpty();
});

it('zeigt nur die Fristen der fragenden Person', function () {
    foreach ([7, 8] as $person) {
        Task::create([
            'title' => "Aufgabe von {$person}",
            'status' => 'offen',
            'kind' => 'general',
            'due_date' => '2026-11-06',
            'assigned_to' => $person,
        ]);
    }

    $eintraege = $this->quelle->events(7, $this->von, $this->bis);

    expect($eintraege)->toHaveCount(1)
        ->and($eintraege[0]->title)->toBe('Aufgabe von 7');
});

it('faengt eine Frist am Rand des Zeitraums mit ein', function () {
    // 23:30 am letzten Tag: Das Raster endet um 23:00, die Aufgabe ist an
    // diesem Tag fällig — nicht in dieser Stunde. Ohne die Weitung auf ganze
    // Tage fiele sie heraus, und zwar lautlos.
    Task::create([
        'title' => 'Spät am Monatsende',
        'status' => 'offen',
        'kind' => 'general',
        'due_date' => '2026-11-30 23:30:00',
        'assigned_to' => 7,
    ]);

    expect($this->quelle->events(7, $this->von, CarbonImmutable::parse('2026-11-30 23:00')))
        ->toHaveCount(1);
});

it('nimmt ein Sprungziel entgegen und laesst es sonst weg', function () {
    Task::create([
        'title' => 'Mit Ziel',
        'status' => 'offen',
        'kind' => 'general',
        'due_date' => '2026-11-07',
        'assigned_to' => 7,
    ]);

    // Ohne Angabe kein Ziel: Besser ein Eintrag ohne Sprung als einer auf eine
    // geratene Adresse.
    expect($this->quelle->events(7, $this->von, $this->bis)[0]->url)->toBeNull();

    $mitZiel = new TaskDeadlineSource(url: fn (Task $a) => "/aufgaben/{$a->getKey()}");

    expect($mitZiel->events(7, $this->von, $this->bis)[0]->url)->toStartWith('/aufgaben/');
});

it('fragt das Aufgabenmodell DIESER Anwendung, nicht sein eigenes', function () {
    // Jede Anwendung erbt vom Paketmodell und traegt eigene Felder. Ohne diese
    // Angabe kaemen Paketmodelle aus der Abfrage, und ein `url`-Haken mit der
    // eigenen Klasse in der Signatur bekaeme das falsche Objekt:
    //
    //     Argument #1 ($aufgabe) must be of type App\Models\Task,
    //     Peppermint\Tasks\Models\Task given
    //
    // Genau so beim ersten Gegentest im Manager aufgetreten (15.10.2026).
    Task::create([
        'title' => 'Eigenes Modell',
        'status' => 'offen',
        'kind' => 'general',
        'due_date' => '2026-11-10',
        'assigned_to' => 7,
    ]);

    $quelle = new TaskDeadlineSource(
        url: fn (EigeneAufgabe $a) => "/eigene/{$a->getKey()}",
        model: EigeneAufgabe::class,
    );

    $eintraege = $quelle->events(7, $this->von, $this->bis);

    expect($eintraege)->toHaveCount(1)
        ->and($eintraege[0]->url)->toStartWith('/eigene/');
});

it('laesst die Anwendung die Auswahl selbst bestimmen', function () {
    // Ein Mandant, ein Team, ein Projekt — das weiß die Anwendung, nicht das
    // Paket. Ohne diesen Haken müsste jede Anwendung mit abweichender Regel
    // die Quelle nachbauen.
    Task::create(['title' => 'A', 'status' => 'offen', 'kind' => 'general', 'due_date' => '2026-11-08', 'assigned_to' => 7]);
    Task::create(['title' => 'B', 'status' => 'offen', 'kind' => 'general', 'due_date' => '2026-11-09', 'assigned_to' => 8]);

    $alle = new TaskDeadlineSource(scope: function ($abfrage) {
        // bewusst ohne Einschränkung auf die Person
    });

    expect($alle->events(7, $this->von, $this->bis))->toHaveCount(2);
});
