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
        $tasks = [];

        foreach ($this->available() as $key => $source) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            try {
                foreach ($source->tasks($userId) as $task) {
                    $tasks[] = $task;
                }
            } catch (Throwable $e) {
                Log::warning('tasks.source_failed', [
                    'source' => $key,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $tasks;
    }
}
