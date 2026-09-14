<?php

namespace Peppermint\Tasks\Status;

use Peppermint\Tasks\Exceptions\UnknownStatus;

/**
 * The status vocabulary of THIS application.
 *
 * Deliberately a registry and not an enum: an enum in the package would force
 * our words on every consumer, and it would not even hold up here — the two
 * Peppermint systems overlap in a single value.
 */
class StatusRegistry
{
    /** @var array<string, TaskStatus> */
    protected array $statuses = [];

    public function register(TaskStatus $status): void
    {
        $this->statuses[$status->key()] = $status;
    }

    public function get(string $key): TaskStatus
    {
        return $this->statuses[$key] ?? throw UnknownStatus::make($key, array_keys($this->statuses));
    }

    public function has(string $key): bool
    {
        return isset($this->statuses[$key]);
    }

    /** @return array<string, TaskStatus> */
    public function all(): array
    {
        return $this->statuses;
    }

    /**
     * The keys that count as finished.
     *
     * Useful for the "what is still open" queries every application writes —
     * so it does not have to spell out its own terminal values a second time
     * and forget one when a tenth state arrives.
     *
     * @return array<int, string>
     */
    public function terminalKeys(): array
    {
        return array_keys(array_filter($this->statuses, fn (TaskStatus $s) => $s->isTerminal()));
    }
}
