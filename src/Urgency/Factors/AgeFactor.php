<?php

namespace Peppermint\Tasks\Urgency\Factors;

use Illuminate\Support\Carbon;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Urgency\UrgencyFactor;

/**
 * A slow upward drift, capped.
 *
 * Small on purpose (0.05 per day, at most 2.0 in the Manager): old tasks
 * should surface eventually, but age alone must never outrank a deadline.
 * Without the cap, a forgotten task from last year would beat everything.
 */
class AgeFactor extends UrgencyFactor
{
    public function key(): string
    {
        return 'age';
    }

    public function label(Task $task): string
    {
        $tage = $this->days($task);

        return 'Alter ('.$tage.' '.($tage === 1 ? 'Tag' : 'Tage').')';
    }

    public function score(Task $task): float
    {
        return min(
            $this->days($task) * (float) config('tasks.urgency.age_per_day', 0.05),
            (float) config('tasks.urgency.age_max', 2.0),
        );
    }

    private function days(Task $task): int
    {
        $created = $task->getAttribute('created_at');

        return $created === null ? 0 : (int) Carbon::instance($created)->diffInDays(Carbon::now());
    }
}
