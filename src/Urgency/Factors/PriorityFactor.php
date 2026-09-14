<?php

namespace Peppermint\Tasks\Urgency\Factors;

use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Urgency\UrgencyFactor;

/** The weight of the task's priority, read off the registered vocabulary. */
class PriorityFactor extends UrgencyFactor
{
    public function key(): string
    {
        return 'priority';
    }

    public function label(Task $task): string
    {
        return 'Priorität ('.($task->priorityDefinition()?->label() ?? 'keine').')';
    }

    public function score(Task $task): float
    {
        return $task->priorityDefinition()?->urgencyCoefficient() ?? 0.0;
    }
}
