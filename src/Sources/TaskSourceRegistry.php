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
     * Collects from every source, skipping the ones named in $skip.
     *
     * A source that is switched off is NOT ASKED, not merely hidden
     * afterwards: it means a call to another system, and that call should not
     * happen when nobody wants to see the result.
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

        foreach ($this->sources as $key => $source) {
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
