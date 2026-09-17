<?php

namespace Peppermint\Tasks\Sources;

/**
 * A task kind as ANOTHER system offers it.
 *
 * Kinds belong to the system that defined them: what one calls a "Ticket" the
 * next has never heard of. Anyone creating a task over there has to name the
 * kind — so the other system must say which ones it has, instead of somebody
 * here guessing.
 *
 * Guessing is the worse answer, and not in a subtle way: a wrong kind files the
 * task in the wrong drawer, and that only shows up on the other side. The
 * calendar package learned this first; the wording of its `ExternalKind` is
 * deliberately mirrored here.
 *
 * Deliberately not a `TaskKind`: that is an application's own kind, with a
 * profile, deletion behaviour and rules. What arrives here is only what a
 * person needs in order to pick one.
 */
final class ForeignKind
{
    /**
     * @param  array<int, string>  $requires  Fields the other system rejects a
     *                                        task without — so a form can ask
     *                                        for them up front instead of
     *                                        showing the error after sending.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $requires = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'requires' => $this->requires,
        ];
    }
}
