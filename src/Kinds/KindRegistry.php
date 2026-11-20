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

    /**
     * The kinds that belong to one area, e.g. a module of the monolith.
     *
     * Kinds without an area are never part of any area. An area nobody
     * registered gives an empty list, not an error: "no CRM tasks yet" is a
     * normal state, not a typo the registry could detect.
     *
     * @return array<string, TaskKind>
     */
    public function forArea(string $area): array
    {
        return array_filter($this->kinds, fn (TaskKind $kind) => $kind->area() === $area);
    }
}
