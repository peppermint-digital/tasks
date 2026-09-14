<?php

namespace Peppermint\Tasks\Sources;

/**
 * Tasks of another system, shown alongside the local ones.
 *
 * Neither system is the centre: each application registers the sources it
 * wants to see. The same mechanism exists for calendars, and it is the piece
 * both systems genuinely share — without it, every application would rebuild
 * the "merge foreign things into my list" logic, which is exactly the
 * duplication these packages exist to end.
 *
 * A source is READ-ONLY by construction. There is no write method here.
 */
abstract class TaskSource
{
    /** Stable identifier, used in the merged id and as the filter key. */
    abstract public function key(): string;

    /** What a human reads on the filter chip. */
    abstract public function label(): string;

    /**
     * The tasks this source offers for the given person, in this system's
     * user id.
     *
     * Resolving that person in the OTHER system is the source's job — the
     * caller must not have to know how two systems recognise the same human.
     *
     * @return array<int, ForeignTask>
     */
    abstract public function tasks(int $userId): array;

    /** Colour for the chip. Null means the application decides. */
    public function colour(): ?string
    {
        return null;
    }
}
