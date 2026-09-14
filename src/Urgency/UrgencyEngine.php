<?php

namespace Peppermint\Tasks\Urgency;

use Peppermint\Tasks\Models\Task;

/**
 * Taskwarrior-style urgency: a weighted sum whose parts stay visible.
 *
 * @see https://taskwarrior.org/docs/urgency/
 *
 * Two properties are worth more than the number itself and are therefore not
 * negotiable here:
 *
 * 1. **The breakdown survives.** A score of 23.4 tells nobody why a task is at
 *    the top. `deadline 18.0 + priority 6.0 − blocked 5.0` does. It is also
 *    the only way to notice that a coefficient is wrong.
 *
 * 2. **It is computed, never stored.** Two of the factors — age and proximity
 *    to a due date — change without anybody writing to the row. A stored
 *    column would be wrong by the first night. If ordering by urgency ever
 *    becomes too expensive, the answer is a cache with an expiry, not a
 *    column.
 */
class UrgencyEngine
{
    public function __construct(
        protected FactorRegistry $factors,
    ) {}

    /** The number, rounded like the Manager has always rounded it. */
    public function score(Task $task): float
    {
        return $this->breakdown($task)['total'];
    }

    /**
     * Score plus the reason for it.
     *
     * @return array{total: float, factors: array<string, array{label: string, value: float}>}
     */
    public function breakdown(Task $task): array
    {
        // A finished task has no urgency — whatever else would apply. Without
        // this, a long-overdue completed task keeps shouting from the top of
        // every list.
        if ($task->statusDefinition()?->isTerminal() ?? false) {
            return ['total' => 0.0, 'factors' => []];
        }

        // Manche Aufgaben laufen nicht im selben Rennen — siehe
        // Task::restrictUrgencyTo().
        $nur = $task->restrictUrgencyTo();

        $parts = [];

        $roh = [];

        foreach ($this->factors->all() as $key => $factor) {
            if ($nur !== null && ! in_array($key, $nur, true)) {
                continue;
            }

            $value = $factor->score($task);

            // Zero is left out, not listed as zero: the breakdown is meant to
            // explain, and a wall of zeroes explains nothing.
            if ($value == 0.0) {
                continue;
            }

            $roh[$key] = $value;

            $parts[$key] = [
                'label' => $factor->label($task),
                // Rounded for reading. The total is NOT the sum of these.
                'value' => round($value, 1),
            ];
        }

        // Summed unrounded, rounded once at the end.
        //
        // Not the sum of the displayed values: rounding every factor first and
        // adding them up drifts by up to half a tenth per factor, and with a
        // dozen factors that is a visibly different number. The score decides
        // what sits at the top of somebody's morning — it must not move
        // because of how it is displayed.
        return [
            'total' => round(array_sum($roh), 1),
            'factors' => $parts,
        ];
    }
}
