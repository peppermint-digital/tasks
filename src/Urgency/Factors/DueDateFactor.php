<?php

namespace Peppermint\Tasks\Urgency\Factors;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Peppermint\Tasks\Models\Task;
use Peppermint\Tasks\Urgency\UrgencyFactor;

/**
 * How close the due date is — the factor that makes the score move on its own.
 *
 * Linear inside a window, full weight from the day it is due, nothing beyond
 * the window. The Manager runs 12.0 over 14 days.
 *
 * An application with a second date of its own (the Manager has `deadline` at
 * 18.0, weighted 1.5x) registers a second factor for it rather than widening
 * this one — the package should not have to know which date a product
 * considers the harder one.
 */
class DueDateFactor extends UrgencyFactor
{
    public function key(): string
    {
        return 'due_date';
    }

    public function label(Task $task): string
    {
        return 'Fälligkeitsdatum';
    }

    public function score(Task $task): float
    {
        $due = $task->field('due_date');

        return $due === null ? 0.0 : static::proximity(
            $due instanceof DateTimeInterface ? $due : Carbon::parse((string) $due),
            (float) config('tasks.urgency.due_date_max', 12.0),
            (int) config('tasks.urgency.date_window_days', 14),
        );
    }

    /**
     * Shared so an application's own date factor gets the same curve — the
     * whole point being that "three days out" means the same everywhere.
     */
    public static function proximity(DateTimeInterface $date, float $max, int $windowDays): float
    {
        // Whole calendar days, both ends at midnight: without that, a task due
        // this afternoon and one due tonight get different urgency for no
        // reason a human would recognise.
        $daysUntil = Carbon::now()->startOfDay()->diffInDays(
            Carbon::instance($date)->startOfDay(), false
        );

        if ($daysUntil <= 0) {
            return $max;
        }

        if ($daysUntil >= $windowDays) {
            return 0.0;
        }

        return $max * (1 - ($daysUntil / $windowDays));
    }
}
