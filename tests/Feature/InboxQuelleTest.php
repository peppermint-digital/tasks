<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Tasks\Inbox\OffeneAufgabenQuelle;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Tests\Support\Fertig;
use Peppermint\Tasks\Tests\Support\Offen;
use Peppermint\Tasks\Tests\Support\Dringend;
use Peppermint\Tasks\Tests\Support\Person;

/**
 * Die offenen Aufgaben als Inbox-Art (#8206).
 *
 * Der gemeinsame Nenner: offen und mir zugewiesen. „Zur Kenntnis
 * genommen" kennt das Paket nicht — ein Produkt mit eigenem Begriff
 * meldet eine eigene Quelle an.
 */
beforeEach(function (): void {
    config([
        'app.url' => 'https://pm.beispiel.test',
        'tasks.statuses' => [Offen::class, Fertig::class],
        'tasks.priorities' => [Dringend::class],
    ]);

    // Das Paket bringt keine Personentabelle mit — es kennt nur die
    // Kennung in `assigned_to`. Fuer den Test reicht ein Stellvertreter.
    Schema::create('users', function (Blueprint $t): void {
        $t->id();
        $t->string('name');
    });

    $this->ich = Person::create(['name' => 'Ich']);
    $this->andere = Person::create(['name' => 'Andere']);
    $this->quelle = new OffeneAufgabenQuelle;
});

it('liefert nur die Aufgaben, die MIR zugewiesen sind', function (): void {
    Task::create(['title' => 'Meine', 'status' => 'offen', 'assigned_to' => $this->ich->id]);
    Task::create(['title' => 'Fremde', 'status' => 'offen', 'assigned_to' => $this->andere->id]);

    $titel = array_map(fn ($e) => $e->titel, $this->quelle->eintraege($this->ich, 50));

    expect($titel)->toBe(['Meine']);
});

it('laesst erledigte Aufgaben draussen', function (): void {
    Task::create(['title' => 'Offen', 'status' => 'offen', 'assigned_to' => $this->ich->id]);
    Task::create(['title' => 'Fertig', 'status' => 'fertig', 'assigned_to' => $this->ich->id]);

    expect($this->quelle->eintraege($this->ich, 50))->toHaveCount(1);
});

it('macht den Verweis absolut', function (): void {
    // Dieselbe Zeile steht gleich in vier Oberflaechen. Eine relative
    // Adresse zeigt in dreien davon ins Leere.
    $a = Task::create(['title' => 'Meine', 'status' => 'offen', 'assigned_to' => $this->ich->id]);

    expect($this->quelle->eintraege($this->ich, 50)[0]->link)
        ->toBe('https://pm.beispiel.test/tasks/'.$a->getKey());
});

it('haelt sich an die Grenze und schneidet das Aelteste ab', function (): void {
    foreach (['Alt', 'Mittel', 'Neu'] as $i => $titel) {
        Task::create([
            'title' => $titel, 'status' => 'offen', 'assigned_to' => $this->ich->id,
            'created_at' => now()->subDays(3 - $i),
        ]);
    }

    $titel = array_map(fn ($e) => $e->titel, $this->quelle->eintraege($this->ich, 2));

    expect($titel)->toBe(['Neu', 'Mittel']);
});

it('steht unter der Art `task`', function (): void {
    expect($this->quelle->art())->toBe('task')
        ->and(OffeneAufgabenQuelle::ART)->toBe('task');
});


it('traegt Status und Prioritaet als Zusatz, ohne daran zu scheitern', function (): void {
    // Diese Pruefung gibt es, weil der Pfad MIT Prioritaet zuerst
    // ungemessen war: Die Quelle rief `color()` auf, das es an
    // `TaskPriority` gar nicht gibt. Ohne eine Aufgabe mit Prioritaet
    // blieb der Lauf gruen — und waere beim ersten echten Datensatz
    // fatal gewesen.
    Task::create([
        'title' => 'Dringende Sache',
        'status' => 'offen',
        'priority' => 'dringend',
        'assigned_to' => $this->ich->id,
    ]);

    $eintrag = $this->quelle->eintraege($this->ich, 50)[0];

    expect($eintrag->meta)->toHaveKey('priority')
        ->and($eintrag->meta['status'])->toBe('Offen')
        ->and($eintrag->farbe)->toBe('blue');
});
