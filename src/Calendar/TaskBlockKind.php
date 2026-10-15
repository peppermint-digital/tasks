<?php

namespace Peppermint\Tasks\Calendar;

use Peppermint\Calendar\Kinds\PlannedBlockKind;
use Peppermint\Tasks\Models\Task;

/**
 * Geplante Arbeitszeit an einer Aufgabe — die Form, die alle Systeme teilen.
 *
 * ## Warum diese Klasse HIER liegt und nicht im Kalender
 *
 * Die Abhaengigkeit laeuft vom Aufgabenpaket zum Kalender, nie umgekehrt. Eine
 * Aufgabe hat eine Frist und eine geplante Zeit; der Kalender ist das Werkzeug,
 * sie zu setzen und zu sehen.
 *
 * Andersherum muesste der Kalender wissen, was eine Aufgabe ist. Dann, was ein
 * Ticket ist. Dann, was ein Deal ist — und waere fuer niemanden mehr
 * installierbar, der nichts davon hat. `PlannedBlockKind` dort beschreibt
 * deshalb nur „etwas Geplantes": Der Termin ist nicht die Sache selbst, sondern
 * ihre Planung.
 *
 * ## Die Doppelung, die sie abloest
 *
 * Gemessen am 15.10.2026 hatten drei Systeme dieselbe Art fast gleich gebaut:
 *
 *     Manager      ManagerTaskKind       77 Zeilen
 *     CRM          AufgabenZeitblockArt  60 Zeilen
 *     Verwaltung   TicketZeitblockArt    68 Zeilen
 *
 * Mit denselben vier bis fuenf Methoden, und `rules()` sagte ueberall dasselbe:
 * `['task_id' => 'required']`. Dabei leitet `PlannedBlockKind::subjectKey()`
 * den Schluessel ohnehin aus dem Modell ab — die Regel war eine Wiederholung
 * dessen, was schon feststand.
 *
 * ## Was ein System noch angeben muss
 *
 * Zwei Dinge:
 *
 * ```php
 * class ManagerTaskKind extends TaskBlockKind
 * {
 *     public function key(): string          { return 'manager_task'; }
 *     public function profileModel(): ?string { return TimeBlockProfile::class; }
 * }
 * ```
 *
 * Der Schluessel bleibt produkteigen, weil er in den Daten steht
 * (`calendar_events.kind`) und in den gespeicherten Filtern der Nutzer. Ein
 * gemeinsamer Schluessel ueber alle Systeme hiesse, dass ein Zeitblock des
 * Managers im CRM wie ein eigener aussieht.
 *
 * Das Profil bleibt produkteigen, weil die Tabelle es ist.
 *
 * ## Welches Aufgabenmodell
 *
 * Standardmaessig das des Pakets. Wer ein eigenes fuehrt — etwa mit zusaetzlichen
 * Spalten — ueberschreibt `subjectModel()`. Davon haengt auch der Feldname ab:
 * `Task` ergibt `task_id`, `Ticket` ergaebe `ticket_id`.
 */
abstract class TaskBlockKind extends PlannedBlockKind
{
    public function label(): string
    {
        return 'Aufgaben-Zeitblock';
    }

    public function subjectModel(): string
    {
        return Task::class;
    }
}
