<?php

namespace Peppermint\Tasks\Reminders;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Peppermint\Reminders\Abgleich\Abgleich;
use Peppermint\Reminders\Abgleich\ErinnerungsAnlass;
use Peppermint\Tasks\Models\Task;

/**
 * Turns a due date into reminders somebody actually receives.
 *
 * ## The point
 *
 * A due date sorts a list and lifts an urgency score. It tells nobody. In AI
 * Brain on 17.09.2026 there were five open tasks with a deadline and four of
 * them were due or overdue — with no one aware of it. The low count is itself
 * the finding: set a deadline, never be reminded, stop setting deadlines.
 *
 * ## Why this generates rows instead of sending anything
 *
 * It would have been shorter to notify directly. It would also have been a
 * SECOND delivery path next to the reminders people set by hand — with its
 * own repeat-lock, its own idea of "already told you", its own drift.
 *
 * So the deadline produces an ordinary {@see TaskReminder}. Delivery, the
 * once-only lock, the bell, the push, dismissal and history are the ones that
 * already exist. And the deadline reminder shows up in the same list as the
 * hand-set ones: dismissible, deletable, visible.
 *
 * ## What "reconcile" means
 *
 * Not "create once and forget". A deadline that moves must take its reminder
 * with it, a deadline that disappears must take it away, and a task that gets
 * finished must stop nagging. None of that can be expressed as an offset
 * computed at read time — which is why the row stays absolute and a rule owns
 * it.
 *
 * ## The one that is already overdue
 *
 * Both moments lie in the past, and creating both would mean two
 * notifications in the same run about the same thing. That is how people
 * learn to ignore a bell. A task whose deadline has passed gets exactly one
 * row.
 */
class DueDateReminders implements ErinnerungsAnlass
{
    /** The advance warning. */
    public const SOURCE_LEAD = 'due:lead';

    /** The one on the day itself. */
    public const SOURCE_DAY = 'due:day';

    /**
     * @param  Closure(Task): (int|null)|null  $recipient  Who to remind. The
     *   package only knows `assigned_to`; a product that also wants to fall
     *   back to the creator passes its own resolver — the creator column is
     *   not the package's to assume.
     */
    public function __construct(private ?Closure $recipient = null) {}

    /**
     * Bring the generated reminders in line with the deadlines.
     *
     * ## Seit 26.10.2026 nur noch die Haelfte
     *
     * Das Anlegen, Verschieben, Abgehaktes-in-Ruhe-Lassen, Wegnehmen und
     * Zaehlen macht {@see Abgleich} im Erinnerungs-Paket — einmal fuer alle
     * Anlaesse. Diese Klasse beantwortet nur noch, WELCHE Aufgaben eine
     * Erinnerung verdienen und WANN.
     *
     * Grund: Das Kalender-Paket hatte dieselben 240 Zeilen ein zweites Mal,
     * verschieden war genau `momente()`. Pruefrage 3 des Paketvertrags.
     *
     * ## Die Bilanz behaelt ihre Form
     *
     * Absichtlich, und zwar wegen `moved_ids`: AI Brain fuehrt eine EIGENE
     * Einmal-Sperre (ein Cache-Schluessel `erinnerung:gemeldet:{id}`, aelter
     * als `delivered_at`) und vergisst sie anhand dieser Liste. Ohne sie
     * feuert dort eine verschobene Erinnerung zum neuen Zeitpunkt NIE —
     * lautlos, weil die Zeile richtig aussieht.
     *
     * @return array{created: int, moved: int, removed: int, unaddressed: int, moved_ids: list<int>}
     */
    public function reconcile(): array
    {
        $bilanz = (new Abgleich($this))->fahren();

        return [
            'created' => $bilanz['angelegt'],
            'moved' => $bilanz['verschoben'],
            'removed' => $bilanz['weggenommen'],
            'unaddressed' => count($bilanz['ohne_empfaenger']),
            'moved_ids' => array_map('intval', $bilanz['verschobene_ids']),
        ];
    }

    /** @return array<int, string> */
    public function quellen(): array
    {
        return [self::SOURCE_LEAD, self::SOURCE_DAY];
    }

    /**
     * Die Aufgaben-Klasse des PRODUKTS, nicht die des Pakets.
     *
     * Daran haengt der Morph-Typ in `subject_type`. Mit der Paket-Klasse
     * stuende dort ein Modell, das kein Produkt instanziiert — und nichts
     * findet die Erinnerungen wieder.
     *
     * @return class-string<Task>
     */
    public function gegenstandsKlasse(): string
    {
        return (string) config('tasks.task_model', Task::class);
    }

    public function gegenstaende()
    {
        $klasse = $this->gegenstandsKlasse();

        return $klasse::query()
            ->open()
            ->whereNotNull($klasse::column('due_date'));
    }

    public function empfaenger(Model $gegenstand): int|string|null
    {
        if ($this->recipient !== null) {
            return ($this->recipient)($gegenstand);
        }

        return $gegenstand->field('assigned_to');
    }

    /**
     * The moments this deadline deserves, in UTC.
     *
     * @return array<string, DateTimeInterface>
     */
    public function momente(Model $gegenstand): array
    {
        $task = $gegenstand;

        $zone = (string) config('tasks.due_reminders.timezone', 'Europe/Berlin');
        $stunde = (int) config('tasks.due_reminders.hour', 8);
        $vorlauf = config('tasks.due_reminders.lead_days', 1);

        $frist = CarbonImmutable::parse($task->field('due_date'))->setTimezone($zone);
        $tag = $frist->setTime($stunde, 0)->utc();

        $momente = [self::SOURCE_DAY => $tag];

        if ($vorlauf !== null) {
            $vorwarnung = $tag->subDays((int) $vorlauf);

            // Der Vorlauf faellt weg, sobald der Tag selbst schon dran ist.
            //
            // Dann sagt die Meldung zum Tag alles, was die Vorwarnung sagen
            // wollte — und beide anzulegen hiesse ZWEI Benachrichtigungen im
            // selben Durchgang fuer dieselbe Sache. Genau so lernt man, bei
            // der Glocke wegzusehen.
            //
            // „Vorwarnung liegt in der Vergangenheit" allein reicht als
            // Bedingung NICHT: Bei einer Aufgabe, die morgen faellig ist, ist
            // sie das seit heute frueh — und die Meldung „morgen faellig" ist
            // dann genau richtig.
            $jetzt = CarbonImmutable::now();

            if ($vorwarnung->greaterThan($jetzt) || $tag->greaterThan($jetzt)) {
                $momente[self::SOURCE_LEAD] = $vorwarnung;
            }
        }

        return $momente;
    }

    /**
     * Was in der Meldung steht.
     *
     * „Frist heute" ueber einen Termin vom April ist schlicht falsch — und
     * aufgefallen ist es erst in der Glocke, am echten Datensatz: Der Titel
     * sah in jedem Test richtig aus, weil dort keine Frist monatealt war.
     *
     * Verglichen wird auf TAGES-Ebene, nicht mit dem Zeitpunkt der Meldung:
     * Eine heute um 08:00 faellige Erinnerung, die um 14:41 zugestellt wird,
     * ist nicht ueberfaellig — der Tag laeuft noch.
     */
    public function wortlaut(Model $gegenstand, string $quelle): string
    {
        $task = $gegenstand;
        $source = $quelle;

        $frist = CarbonImmutable::parse($task->field('due_date'));
        $text = $frist->format('d.m.Y');

        if ($source === self::SOURCE_LEAD) {
            return "Frist naht: {$text}";
        }

        $zone = (string) config('tasks.due_reminders.timezone', 'Europe/Berlin');

        return $frist->setTimezone($zone)->startOfDay()->lessThan(CarbonImmutable::now($zone)->startOfDay())
            ? "Frist ueberfaellig: {$text}"
            : "Frist heute: {$text}";
    }

}
