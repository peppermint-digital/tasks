<?php

namespace Peppermint\Tasks\Urgency;

use Peppermint\Tasks\Models\Task;

/**
 * One contribution to a task's urgency score.
 *
 * The engine is shared, the factors are not — and that is the whole point of
 * this class existing.
 *
 * Of the fourteen factors the Peppermint Manager has been running, NINE reach
 * into application-owned relations: comments, blockers, tags, the routine
 * flag — and "scheduled", which reaches into calendar time blocks. Wiring
 * those into the package would mean `peppermint/tasks` could not be shipped
 * without `peppermint/calendar`, and a calendar could not be shipped without a
 * task manager.
 *
 * So the package ships only what needs nothing but the task itself. Everything
 * that knows something extra is registered by whoever knows it.
 */
abstract class UrgencyFactor
{
    /** Stable identifier, used as the key in the breakdown. */
    abstract public function key(): string;

    /** What a human reads in the breakdown. May name the concrete value. */
    abstract public function label(Task $task): string;

    /**
     * The contribution. Zero means "nothing to say here" and is left out of
     * the breakdown entirely — a list of zeroes explains nothing.
     *
     * Negative is allowed and meant: being blocked LOWERS urgency, because a
     * task nobody can start should not sit at the top of the list.
     */
    abstract public function score(Task $task): float;
}
