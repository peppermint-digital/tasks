<?php

namespace Peppermint\Tasks\Priority;

use Peppermint\Tasks\Exceptions\UnknownPriority;

/** The priority vocabulary of THIS application. See {@see \Peppermint\Tasks\Status\StatusRegistry}. */
class PriorityRegistry
{
    /** @var array<string, TaskPriority> */
    protected array $priorities = [];

    public function register(TaskPriority $priority): void
    {
        $this->priorities[$priority->key()] = $priority;
    }

    public function get(string $key): TaskPriority
    {
        return $this->priorities[$key] ?? throw UnknownPriority::make($key, array_keys($this->priorities));
    }

    public function has(string $key): bool
    {
        return isset($this->priorities[$key]);
    }

    /** @return array<string, TaskPriority> */
    public function all(): array
    {
        return $this->priorities;
    }
}
