<?php

namespace Peppermint\Tasks\Status;

/**
 * One state in an application's task vocabulary.
 *
 * The package ships none. Peppermint Manager knows nine (`backlog`, `waiting`,
 * `todo`, `in_progress`, `review`, `testing`, `completed`, `cancelled`,
 * `routine`), AI Brain four (`open`, `in_progress`, `blocked`, `done`) — and
 * exactly ONE of them, `in_progress`, is spelled the same in both.
 *
 * Forcing a shared word would mean rewriting every row, every query, every
 * filter and every screen of one of the two systems for a cosmetic gain. What
 * they have to agree on is {@see VtodoStatus}, and that is an external
 * standard rather than our preference.
 */
abstract class TaskStatus
{
    /** The value as it is stored in this application's rows. */
    abstract public function key(): string;

    /** What a human reads. */
    abstract public function label(): string;

    /**
     * What this state means to the outside world.
     *
     * Drives export, the terminal check and anything that compares tasks
     * across two systems.
     */
    abstract public function vtodo(): VtodoStatus;

    /**
     * Weight this state contributes to the urgency score.
     *
     * On the entry, not in a central table: a state that no application
     * registered has no weight, and one that two applications weigh
     * differently is two entries — which is the honest answer.
     *
     * Zero means "no opinion", not "unimportant".
     */
    public function urgencyCoefficient(): float
    {
        return 0.0;
    }

    /** Finished — no urgency, no reminders. Read off the VTODO meaning. */
    final public function isTerminal(): bool
    {
        return $this->vtodo()->isTerminal();
    }
}
