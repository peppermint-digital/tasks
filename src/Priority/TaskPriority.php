<?php

namespace Peppermint\Tasks\Priority;

/**
 * One step in an application's priority vocabulary.
 *
 * Same principle as {@see \Peppermint\Tasks\Status\TaskStatus}: the package
 * ships none. Manager has five (`low`, `medium`, `high`, `important`,
 * `urgent`), Brain four (`low`, `medium`, `high`, `critical`) — three overlap,
 * and the two top ends mean different things.
 */
abstract class TaskPriority
{
    abstract public function key(): string;

    abstract public function label(): string;

    /**
     * Weight this step contributes to the urgency score.
     *
     * The Manager's running values are 8.0 / 7.0 / 6.0 / 3.9 / 1.8 — note that
     * they are not evenly spaced. That is on purpose and comes from
     * Taskwarrior: the gap between "medium" and "high" is meant to be larger
     * than the one between "low" and "medium", because the step from
     * nice-to-have to actually-important is the bigger one.
     */
    abstract public function urgencyCoefficient(): float;

    /**
     * iCalendar PRIORITY (RFC 5545 §3.8.1.9): 1 is highest, 9 lowest,
     * 0 means "not defined".
     *
     * The external yardstick again, so a task can leave the house. Rough
     * convention: 1–4 high, 5 medium, 6–9 low.
     */
    abstract public function vtodo(): int;
}
