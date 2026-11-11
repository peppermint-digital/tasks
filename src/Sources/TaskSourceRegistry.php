<?php

namespace Peppermint\Tasks\Sources;

use Illuminate\Support\Facades\Log;
use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
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
     * The sources that answer the PERSON question right now.
     *
     * What an interface builds its filter chips from. A source that only
     * answers about records does not belong there — see
     * {@see TaskSource::supportsPerson()}.
     *
     * @return array<string, TaskSource>
     */
    public function forPersons(): array
    {
        return array_filter($this->available(), fn (TaskSource $source) => $source->supportsPerson());
    }

    /**
     * The sources that can answer about a subject right now.
     *
     * Two conditions, not one: available (the person has access at all) AND
     * willing to answer subject questions. Built on `available()` for the same
     * reason `writable()` is — a product nobody can reach is not asked about
     * records either.
     *
     * @return array<string, TaskSource>
     */
    public function forSubjects(): array
    {
        return array_filter($this->available(), fn (TaskSource $source) => $source->supportsSubjects());
    }

    /**
     * Collects the foreign tasks belonging to ONE record — and what failed.
     *
     * ## Why this does not swallow like `collect()` does
     *
     * `collect()` logs a broken source and moves on, and the reasoning holds
     * there: that is the person's own task list, it is long, and one missing
     * chip is invisible next to a blank page.
     *
     * Here the list is short and the question is closed. Someone opens an
     * order and asks "what else belongs to this?". Three entries where there
     * should be five looks exactly like a complete answer — there is nothing
     * on the page to notice. So the failure is not logged away, it is returned
     * (see {@see TaskHarvest}), and the interface says which system is missing.
     *
     * Still not thrown: the other sources keep showing, and the page works.
     *
     * Answers in the same shape as {@see harvest()} — same class, same three
     * fields. A page that shows a record's tasks next to a person's reads one
     * form, not two, and the caveat line is built the same way on both halves.
     *
     * @param  string  $type  the other system's word for its own record
     * @param  array<int, string>  $skip  sources the person switched off
     */
    public function collectForSubject(string $type, string|int $id, array $skip = []): TaskHarvest
    {
        $tasks = [];
        $ok = [];
        $failures = [];

        foreach ($this->forSubjects() as $key => $source) {
            if (in_array($key, $skip, true)) {
                // Switched off is NOT a failure: nobody is waiting for this
                // answer, so its absence needs no caveat.
                continue;
            }

            try {
                foreach ($source->tasksForSubject($type, $id) as $task) {
                    $tasks[] = $task;
                }

                // Also noted when it brought nothing, and here the reason is
                // sharper than for a person: "this order has no CRM tasks" is
                // an answer somebody acts on, and it must not look like "the
                // CRM was never asked".
                $ok[] = $key;
            } catch (Throwable $e) {
                if (! $e instanceof ForeignSourceFailed) {
                    // Anything else is a defect on our side, not an outage over
                    // there — it still must not blank the page, and it still
                    // must not pass as "nothing found".
                    Log::warning('tasks.subject_source_failed', [
                        'source' => $key,
                        'subject_type' => $type,
                        'message' => $e->getMessage(),
                    ]);
                }

                $failures[$key] = $this->failureFrom($key, $e);
            }
        }

        return new TaskHarvest($tasks, $ok, $failures);
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
        $failures = [];

        // `forPersons()`, not `available()`: a subject-only source would be
        // asked and would answer nothing, which costs a call across a system
        // boundary for a question it does not hold.
        foreach ($this->forPersons() as $key => $source) {
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

                $failures[$key] = $this->failureFrom($key, $e);
            }
        }

        return new TaskHarvest($tasks, $ok, $failures);
    }

    /**
     * A throw from a source, turned into the answer's failure entry.
     *
     * One place for both queries, because the reading is the same one twice:
     * the MESSAGE is the other end's own words, passed through — "Verwaltung
     * nicht erreichbar: timeout" tells somebody what to do next, "Fehler" does
     * not — and the REASON is the comm layer's class, when the source knew one.
     *
     * A plain throw carries no class, and that stays null rather than being
     * guessed at: a source that cannot tell "not allowed" from "not reachable"
     * must not have one of them invented for it here. Guessing would be the
     * expensive kind of wrong, because the interface acts on the reason — it
     * offers a reload for one and tells somebody to ask for access for another.
     */
    protected function failureFrom(string $key, Throwable $e): SourceFailure
    {
        return new SourceFailure(
            $key,
            $e->getMessage(),
            $e instanceof ForeignSourceFailed ? $e->errorClass : null,
        );
    }
}
