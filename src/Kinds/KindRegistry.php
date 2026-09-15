<?php

namespace Peppermint\Tasks\Kinds;

use Peppermint\Tasks\Exceptions\UnknownTaskKind;

/**
 * The kinds of task THIS application has.
 *
 * A registry and not an enum, for the same reason as the status vocabulary:
 * the kinds differ per product and there is no set the package could impose.
 * A ticket is a kind in the Verwaltung and meaningless in the CRM.
 */
class KindRegistry
{
    /** @var array<string, TaskKind> */
    protected array $kinds = [];

    public function register(TaskKind $kind): void
    {
        $this->kinds[$kind->key()] = $kind;
    }

    public function get(string $key): TaskKind
    {
        return $this->kinds[$key] ?? throw UnknownTaskKind::make($key, array_keys($this->kinds));
    }

    public function has(string $key): bool
    {
        return isset($this->kinds[$key]);
    }

    /** @return array<string, TaskKind> */
    public function all(): array
    {
        return $this->kinds;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->kinds);
    }

    /**
     * The kinds a person may pick when creating a task by hand.
     *
     * @return array<string, TaskKind>
     */
    public function creatable(): array
    {
        return array_filter($this->kinds, fn (TaskKind $kind) => $kind->isUserCreatable());
    }
}
