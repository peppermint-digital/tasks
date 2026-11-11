<?php

namespace Peppermint\Tasks\Sources;

/**
 * What the foreign sources answered about one subject — and what they could not.
 *
 * ## Why this is not just an array
 *
 * A plain list cannot tell "this record has no CRM tasks" apart from "the CRM
 * could not be asked". Both arrive as nothing, and the second one is a
 * question the person has to be allowed to see. A record that quietly shows
 * three of its five tasks reads as complete.
 *
 * This is the one place the two rules of this package meet, and they only look
 * contradictory:
 *
 * - {@see \Peppermint\Tasks\Exceptions\ForeignSourceFailed} says reading must
 *   not fail loudly — "a task list left empty because somebody else's server
 *   is slow would be worse than one that is incomplete".
 * - The subject query says the opposite — an empty list and "I was not allowed
 *   to look" must not be the same answer.
 *
 * Both hold. Do not THROW (the page keeps working, the other sources still
 * show), but do not HIDE either: the failure travels as data, so the interface
 * can say which system is missing and why. Logging it instead puts the doubt
 * where the person never looks.
 */
final class SubjectTasks
{
    /**
     * @param  array<int, ForeignTask>  $tasks
     * @param  array<string, SubjectSourceFailure>  $failures  keyed by source
     */
    public function __construct(
        public readonly array $tasks = [],
        public readonly array $failures = [],
    ) {}

    /**
     * Is something missing from this answer?
     *
     * An interface asks this to decide whether to print the caveat. Not the
     * same as "no tasks": a subject can honestly have none while every source
     * answered.
     */
    public function isIncomplete(): bool
    {
        return $this->failures !== [];
    }

    /** @return array<int, string> the keys of the sources that could not be asked */
    public function missingSources(): array
    {
        return array_keys($this->failures);
    }
}
