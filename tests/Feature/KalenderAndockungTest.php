<?php

use Peppermint\Calendar\Kinds\PlannedBlockKind;
use Peppermint\Tasks\Calendar\TaskBlockKind;
use Peppermint\Tasks\Models\Task;

/**
 * Die Kalender-Andockung des Aufgabenpakets (#7786).
 *
 * ## Die Richtung
 *
 * `peppermint/tasks` → `peppermint/calendar`, nie umgekehrt. Eine Aufgabe hat
 * eine Frist und eine geplante Zeit; der Kalender ist das Werkzeug, sie zu
 * setzen und zu sehen.
 *
 * Andersherum muesste der Kalender wissen, was eine Aufgabe ist — und waere
 * fuer niemanden mehr installierbar, der keine hat.
 *
 * ## Und sie bleibt optional
 *
 * `suggest`, nicht `require`. Wer dieses Paket ohne Kalender installiert,
 * bekommt Aufgaben ohne Zeitplanung und merkt von diesen Klassen nichts —
 * Autoloading ist faul.
 */
it('leitet den Feldnamen aus dem Aufgabenmodell ab', function () {
    // Die drei Systeme schrieben bis zum 15.10.2026 alle
    // `rules() => ['task_id' => 'required']` — eine Wiederholung dessen, was
    // `PlannedBlockKind::subjectKey()` ohnehin ableitet.
    $art = new class extends TaskBlockKind
    {
        public function key(): string
        {
            return 'probe_task';
        }
    };

    expect($art->subjectKey())->toBe('task_id')
        ->and($art->subjectModel())->toBe(Task::class);
});

it('ist ein geplanter Block und erbt dessen Regeln', function () {
    $art = new class extends TaskBlockKind
    {
        public function key(): string
        {
            return 'probe_task';
        }
    };

    expect($art)->toBeInstanceOf(PlannedBlockKind::class)
        // Von Hand anlegbar ist ein Zeitblock nicht: Er entsteht durch Ziehen
        // einer Aufgabe ins Raster. Wer ihn in einer Auswahlliste anbietet,
        // baut eine Maske, die beim Speichern abgelehnt wird.
        ->and($art->isUserCreatable())->toBeFalse();
});

it('laesst ein System nur Schluessel und Profil angeben', function () {
    // Der Schluessel bleibt produkteigen, weil er in den Daten steht
    // (`calendar_events.kind`) und in gespeicherten Filtern. Ein gemeinsamer
    // Schluessel hiesse, dass ein Zeitblock des Managers im CRM wie ein
    // eigener aussieht.
    $art = new class extends TaskBlockKind
    {
        public function key(): string
        {
            return 'manager_task';
        }
    };

    expect($art->key())->toBe('manager_task')
        ->and($art->label())->toBe('Aufgaben-Zeitblock')
        // Ohne eigenes Profil: Der Gegenstand steht dann in
        // `subject_type`/`subject_id` am Kern.
        ->and($art->profileModel())->toBeNull();
});

it('nimmt ein eigenes Aufgabenmodell an und zieht den Feldnamen nach', function () {
    // Wer ein eigenes Modell fuehrt — etwa ein Ticket statt einer Aufgabe —
    // ueberschreibt `subjectModel()`. Der Feldname folgt daraus, er wird nicht
    // zweimal gepflegt.
    $art = new class extends TaskBlockKind
    {
        public function key(): string
        {
            return 'verwaltung_ticket';
        }

        public function subjectModel(): string
        {
            return \Peppermint\Tasks\Tests\Fixtures\Ticket::class;
        }
    };

    expect($art->subjectKey())->toBe('ticket_id');
});
