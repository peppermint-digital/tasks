<?php

namespace Peppermint\Tasks\Sources;

/**
 * What came back from one round of asking the sources — including what did not.
 *
 * ## Why an outage has to stand in the ANSWER
 *
 * {@see TaskSourceRegistry::collect()} swallows a failing source and logs it.
 * For a page that merely draws a list, that is right: a task list left blank
 * because somebody else's server is slow is worse than one that is incomplete.
 *
 * For a form it is not enough, and for a record it is wrong. The planning mask
 * asks a question collect() cannot answer — "pick a system, then a task" — and
 * the two answers it has to tell apart look identical through collect():
 *
 *   - **"no open tasks"**  → the person is done there. Showing "nothing to do"
 *                            is correct.
 *   - **"was not reachable"** → unknown. Showing "nothing to do" is a lie, and
 *                            whoever acts on it plans their day around tasks
 *                            they still have.
 *
 * Someone opening an order and asking "what else belongs to this?" has it
 * worse still: the list is short and the question is closed, so three entries
 * where there should be five looks exactly like a complete answer. There is
 * nothing on the page to notice.
 *
 * A log line reaches neither of them. So the failure travels back with the
 * result, and the mask can say "CRM could not be reached" instead of silently
 * shortening the list.
 *
 * ## One form for BOTH questions
 *
 * There were two of these classes for the length of one day (11.11.2026):
 * `TaskHarvest` for "this person's tasks per system" and `SubjectTasks` for
 * "this record's tasks per system", written in parallel, each missing what the
 * other had. They are now one class, and that is not tidiness:
 *
 * A mask that shows both — the planning form does, a record page will — had to
 * read two shapes for one question, and the shape it got decided whether it
 * could even ASK whether a system answered. Two answers to "is this list
 * complete?" in one view is how a caveat ends up on the wrong half.
 *
 * ## Reading the three fields
 *
 * `failures` empty and `tasks` empty means **really** nothing open — that is
 * the sentence the mask may rely on, and the only reason this class exists.
 *
 * `ok` is not derivable from the tasks: a source that answers with an empty
 * list is in `ok` and contributes nothing to `tasks`. Without it, "answered
 * and had nothing" and "was skipped" would look the same again, one level up —
 * which is the distinction the whole round of work was for.
 */
final class TaskHarvest
{
    /**
     * @param  array<int, ForeignTask>  $tasks  Every task of every source that answered, in asking order.
     * @param  array<int, string>  $ok  The keys of the sources that answered — including with nothing.
     * @param  array<string, SourceFailure>  $failures  Source key => why it stayed silent. A class and
     *                                                  not a sentence: "not allowed" and "not reachable"
     *                                                  lead to different next steps, and a mask that
     *                                                  cannot tell them apart offers a reload for a
     *                                                  missing permission.
     */
    public function __construct(
        public readonly array $tasks = [],
        public readonly array $ok = [],
        public readonly array $failures = [],
    ) {}

    /** Did every source that was asked answer? */
    public function complete(): bool
    {
        return $this->failures === [];
    }

    /**
     * Really nothing open — as opposed to "nothing visible".
     *
     * The one question a mask must not get wrong, in one place so it is not
     * re-derived from two fields at every call site.
     */
    public function empty(): bool
    {
        return $this->tasks === [] && $this->complete();
    }

    /**
     * The keys of the sources that could not be asked.
     *
     * For the caveat line: "Verwaltung and CRM are missing" is what a person
     * can act on, and it is shorter than the failures themselves.
     *
     * @return array<int, string>
     */
    public function missingSources(): array
    {
        return array_keys($this->failures);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tasks' => array_map(static fn (ForeignTask $task) => $task->toArray(), $this->tasks),
            'ok' => $this->ok,
            // `failures`, and no longer `failed`: the value is an object now,
            // not a string. Keeping the old key would have let a consumer
            // render it unchanged and print "[object Object]" where the reason
            // belongs — a rename breaks loudly instead, at the one moment
            // somebody is looking.
            'failures' => array_map(static fn (SourceFailure $failure) => $failure->toArray(), $this->failures),
            // Named explicitly rather than left to the reader: the whole point
            // of this object is that a shortened list is recognisable AS
            // shortened, and a consumer reading JSON gets the same answer the
            // PHP caller gets.
            'complete' => $this->complete(),
        ];
    }
}
