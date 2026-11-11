<?php

namespace Peppermint\Tasks\Sources;

/**
 * What came back from one round of asking every source — including what did
 * not come back.
 *
 * ## Why an outage has to stand in the ANSWER
 *
 * {@see TaskSourceRegistry::collect()} swallows a failing source and logs it.
 * For a page that merely draws a list, that is right: a task list left blank
 * because somebody else's server is slow is worse than one that is incomplete.
 *
 * For a form it is not enough. The planning mask asks a question collect()
 * cannot answer — "pick a system, then a task" — and the two answers it has to
 * tell apart look identical through collect():
 *
 *   - **"no open tasks"**  → the person is done there. Showing "nothing to do"
 *                            is correct.
 *   - **"was not reachable"** → unknown. Showing "nothing to do" is a lie, and
 *                            whoever acts on it plans their day around tasks
 *                            they still have.
 *
 * A log line does not reach the person looking at the screen. So the failure
 * travels back with the result, and the mask can say "CRM could not be
 * reached" instead of silently shortening the list.
 *
 * ## Reading the three fields
 *
 * `failed` empty and `tasks` empty means **really** nothing open — that is the
 * sentence the mask may rely on, and the only reason this class exists.
 *
 * `ok` is not derivable from the tasks: a source that answers with an empty
 * list is in `ok` and contributes nothing to `tasks`. Without it, "answered
 * and had nothing" and "was skipped" would look the same again, one level up.
 */
final class TaskHarvest
{
    /**
     * @param  array<int, ForeignTask>  $tasks  Every task of every source that answered, in asking order.
     * @param  array<int, string>  $ok  The keys of the sources that answered — including with nothing.
     * @param  array<string, string>  $failed  Source key => reason, human-readable. The reason is the
     *                                         other end's, passed through: "Verwaltung not reachable:
     *                                         timeout" tells somebody what to do, "error" does not.
     */
    public function __construct(
        public readonly array $tasks = [],
        public readonly array $ok = [],
        public readonly array $failed = [],
    ) {}

    /** Did every source that was asked answer? */
    public function complete(): bool
    {
        return $this->failed === [];
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tasks' => array_map(static fn (ForeignTask $task) => $task->toArray(), $this->tasks),
            'ok' => $this->ok,
            'failed' => $this->failed,
            // Named explicitly rather than left to the reader: the whole point
            // of this object is that a shortened list is recognisable AS
            // shortened, and a consumer reading JSON gets the same answer the
            // PHP caller gets.
            'complete' => $this->complete(),
        ];
    }
}
