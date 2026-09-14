<?php

namespace Peppermint\Tasks\Urgency\Factors;

use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Urgency\UrgencyFactor;

/**
 * The weight of the task's state.
 *
 * Meant to carry negative values too: "backlog" and "waiting" run at −3.0 in
 * the Manager, and that is the point — something parked should sink, not just
 * fail to rise.
 */
class StatusFactor extends UrgencyFactor
{
    public function key(): string
    {
        return 'status';
    }

    public function label(Task $task): string
    {
        return 'Status ('.($task->statusDefinition()?->label() ?? 'keiner').')';
    }

    public function score(Task $task): float
    {
        return $task->statusDefinition()?->urgencyCoefficient() ?? 0.0;
    }
}
