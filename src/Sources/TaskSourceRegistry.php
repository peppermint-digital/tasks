<?php

namespace Peppermint\Tasks\Sources;

use Illuminate\Support\Facades\Log;
use Throwable;

/** The foreign task sources of THIS application. */
class TaskSourceRegistry
{
    /** @var array<string, TaskSource> */
    protected array $sources = [];

    public function register(TaskSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /** @return array<string, TaskSource> */
    public function all(): array
    {
        return $this->sources;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    /**
     * The sources that are usable right now.
     *
     * This is where autonomy becomes visible: a product the person has no
     * access to does not appear as an empty section or a dead filter entry, it
     * is simply not there. Asked each time rather than cached — access can be
     * withdrawn between two page loads, and a stale "yes" shows a section that
     * then cannot be filled.
     *
     * @return array<string, TaskSource>
     */
    public function available(): array
    {
        return array_filter($this->sources, fn (TaskSource $source) => $source->isAvailable());
    }

    /**
     * The sources you may create in.
     *
     * An interface asks for this instead of checking itself — whoever repeats
     * the check at every control eventually forgets it somewhere. And the check
     * is two conditions, not one: the source has to BE writable (own class, no
     * flag) and to say it may be written to right now (`isWritable()` — the
     * capability can be gone, the person's rights can be withdrawn).
     *
     * Built on `available()`, not on `$sources`: a product nobody has access to
     * is not offered for creating either.
     *
     * @return array<string, WritableTaskSource>
     */
    public function writable(): array
    {
        return array_filter(
            $this->available(),
            fn (TaskSource $source) => $source instanceof WritableTaskSource && $source->isWritable(),
        );
    }

    /**
     * The sources you may perform THIS operation in.
     *
     * Narrower than `writable()`, and that is the point: a product can be
     * writable and still not offer every operation. The Verwaltung is the case
     * that made this necessary — a ticket can be ticked off and deleted, but
     * not created from another system, because a ticket always has a concern
     * behind it.
     *
     * `writable()` stays for the question „may this person write here at all",
     * which is what a page asks before it shows any editing at all.
     *
     * @param  string  $operation  one of WritableTaskSource::CREATE, CHANGE, DELETE
     * @return array<string, WritableTaskSource>
     */
    public function writableFor(string $operation): array
    {
        return array_filter(
            $this->writable(),
            fn (TaskSource $source) => $source instanceof WritableTaskSource && $source->supports($operation),
        );
    }

    /**
     * Collects from every source, skipping the ones named in $skip.
     *
     * A source that is switched off is NOT ASKED, not merely hidden
     * afterwards: it means a call to another system, and that call should not
     * happen when nobody wants to see the result.
     *
     * A source that is not available is not asked either — see available().
     *
     * A source that fails is logged and skipped. One unreachable system must
     * not take down the local task list — a page that shows nothing because
     * somebody else's server is down is worse than a page missing one chip.
     *
     * @param  array<int, string>  $skip
     * @return array<int, ForeignTask>
     */
    public function collect(int $userId, array $skip = []): array
    {
        // Asks `harvest()` and drops everything but the tasks. Same signature,
        // same behaviour, same order — what a caller of this method gets does
        // not change.
        //
        // One body rather than two: the loop carries three decisions (do not
        // ask a skipped source, do not ask an unavailable one, log a failing
        // one and carry on), and two copies of three decisions stay in step
        // only until the first fix touches one of them.
        return $this->harvest($userId, $skip)->tasks;
    }

    /**
     * Collects like `collect()` — and says what did not answer.
     *
     * ## Why there are two methods and not one
     *
     * `collect()` returns tasks, and that is all a page drawing a list needs:
     * an unreachable system is left out, the rest stays visible, the reason
     * goes in the log.
     *
     * A FORM needs more. The planning mask asks "which system, then which
     * task", and through `collect()` the two answers it must tell apart look
     * the same — "no open tasks" and "was not reachable" are both an empty
     * list. The first is correct to show as "nothing to do"; the second is a
     * lie, and somebody plans their day around it.
     *
     * The log does not help there: it does not reach the person looking at the
     * screen. So the failure comes back WITH the result, and `collect()` keeps
     * its old shape for the callers that want the quiet reading — the hub calls
     * it, and nothing there should change because a form needed more detail.
     *
     * Reading stays quiet either way: nothing throws out of here. The
     * difference is only whether the reason is recoverable afterwards.
     *
     * @param  array<int, string>  $skip
     */
    public function harvest(int $userId, array $skip = []): TaskHarvest
    {
        $tasks = [];
        $ok = [];
        $failed = [];

        foreach ($this->available() as $key => $source) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            try {
                foreach ($source->tasks($userId) as $task) {
                    $tasks[] = $task;
                }

                // Noted even when it brought nothing: "answered with nothing"
                // and "was not asked" must stay distinguishable, or the
                // distinction this method exists for is lost one level up.
                $ok[] = $key;
            } catch (Throwable $e) {
                // Logged AS WELL, not instead. The log is what somebody reads
                // when they ask why a system has been quiet for two days; the
                // returned reason is what the person in front of the form reads
                // right now. They answer different questions.
                Log::warning('tasks.source_failed', [
                    'source' => $key,
                    'message' => $e->getMessage(),
                ]);

                // The other end's reason, passed through: "Verwaltung nicht
                // erreichbar: timeout" tells somebody what to do next,
                // "Fehler" does not.
                $failed[$key] = $e->getMessage();
            }
        }

        return new TaskHarvest($tasks, $ok, $failed);
    }
}
