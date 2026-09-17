<?php

namespace Peppermint\Tasks\Reminders;

use Carbon\CarbonImmutable;
use Closure;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Models\TaskReminder;

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
class DueDateReminders
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
     * @return array{created: int, moved: int, removed: int, unaddressed: int, moved_ids: list<int>}
     */
    public function reconcile(): array
    {
        $bilanz = ['created' => 0, 'moved' => 0, 'removed' => 0, 'unaddressed' => 0, 'moved_ids' => []];

        $this->removeStaleRows($bilanz);

        $tasks = Task::query()
            ->open()
            ->whereNotNull(Task::column('due_date'))
            ->with('taskReminders')
            ->get();

        foreach ($tasks as $task) {
            $empfaenger = $this->recipient !== null
                ? ($this->recipient)($task)
                : $task->field('assigned_to');

            if ($empfaenger === null) {
                // Counted, not silently skipped. A run that quietly does
                // nothing reports success, and the gap only surfaces months
                // later when someone wonders why they were never told.
                $bilanz['unaddressed']++;

                continue;
            }

            foreach ($this->momente($task) as $source => $zeitpunkt) {
                $this->abgleichen($task, $source, $zeitpunkt, (int) $empfaenger, $bilanz);
            }
        }

        return $bilanz;
    }

    /**
     * The moments this deadline deserves, in UTC.
     *
     * @return array<string, CarbonImmutable>
     */
    private function momente(Task $task): array
    {
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
     * @param  array{created: int, moved: int, removed: int, unaddressed: int, moved_ids: list<int>}  $bilanz
     */
    private function abgleichen(Task $task, string $source, CarbonImmutable $zeitpunkt, int $empfaenger, array &$bilanz): void
    {
        $vorhanden = $task->taskReminders->firstWhere('source', $source);

        if ($vorhanden === null) {
            $task->taskReminders()->create([
                'user_id' => $empfaenger,
                'remind_at' => $zeitpunkt,
                'source' => $source,
                'title' => $this->wortlaut($task, $source),
            ]);

            $bilanz['created']++;

            return;
        }

        // Abgehaktes bleibt, wie es ist. Es ist der Nachweis, dass jemand
        // erinnert wurde und gehandelt hat — daran nachtraeglich zu drehen
        // hiesse, die Geschichte umzuschreiben.
        if ($vorhanden->is_dismissed) {
            return;
        }

        $verschoben = ! $vorhanden->remind_at->equalTo($zeitpunkt)
            || (int) $vorhanden->user_id !== $empfaenger;

        if (! $verschoben) {
            return;
        }

        $vorhanden->update([
            'remind_at' => $zeitpunkt,
            'user_id' => $empfaenger,
            'title' => $this->wortlaut($task, $source),
        ]);

        $bilanz['moved']++;

        // Der Aufrufer muss die Zustell-Sperre fuer diese Zeile aufheben,
        // sonst feuert die verschobene Erinnerung nie: Sie gilt als „schon
        // gemeldet", obwohl sie zum neuen Zeitpunkt noch nichts gesagt hat.
        $bilanz['moved_ids'][] = (int) $vorhanden->getKey();
    }

    private function wortlaut(Task $task, string $source): string
    {
        $frist = CarbonImmutable::parse($task->field('due_date'))->format('d.m.Y');

        return $source === self::SOURCE_LEAD
            ? "Frist naht: {$frist}"
            : "Frist heute: {$frist}";
    }

    /**
     * Erzeugte Zeilen wegraeumen, die nichts mehr zu sagen haben — die Frist
     * ist weg oder die Aufgabe ist erledigt.
     *
     * Nur die noch offenen. Eine abgehakte bleibt stehen, auch wenn ihr Anlass
     * entfallen ist: Der Verlauf soll zeigen, dass erinnert wurde.
     *
     * @param  array{created: int, moved: int, removed: int, unaddressed: int, moved_ids: list<int>}  $bilanz
     */
    private function removeStaleRows(array &$bilanz): void
    {
        $offen = Task::query()
            ->open()
            ->whereNotNull(Task::column('due_date'))
            ->pluck((new Task)->getKeyName());

        $bilanz['removed'] = TaskReminder::query()
            ->whereIn('source', [self::SOURCE_LEAD, self::SOURCE_DAY])
            ->where('is_dismissed', false)
            ->whereNotIn('task_id', $offen)
            ->delete();
    }
}
