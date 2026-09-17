<?php

namespace Peppermint\Tasks\Sources;

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;

/**
 * A source you may also create in, tick off and delete from.
 *
 * Deliberately its own class and not a flag on `TaskSource`. A flag would be a
 * check somebody forgets, and the consequence would be a write path into a
 * system that was only meant to be displayed. This way a read-only source
 * stays read-only because it does not have the method at all.
 *
 * The task still belongs to the other system: what comes back is a
 * {@see ForeignTask} — no row in this database, exactly as when reading.
 *
 * ## Why this class did not exist for a long time, and what it cost
 *
 * The calendar had `WritableEventSource` from the start; tasks had only the
 * reading half. A product whose connector cannot write has exactly one way to
 * let a person create a task: put it into a system that CAN be written to. The
 * CRM did that — every task belonging to a deal was created in the project
 * manager. Not as a design, but because there was no other route.
 *
 * That is the very copy these packages exist to prevent, and it was invisible
 * for as long as nobody asked why the CRM's own task table sat empty.
 */
abstract class WritableTaskSource extends TaskSource
{
    /**
     * The task kinds of the other system, so a person can pick one instead of
     * guessing. Empty means: it names none, and the caller has to rely on the
     * target system's default.
     *
     * @return array<int, ForeignKind>
     */
    abstract public function kinds(): array;

    /**
     * Creates the task over there and returns what the other system made of it
     * — not what we sent. The two can differ: the target system applies
     * defaults, derives an urgency from factors we do not have, or attaches its
     * own identifier, and its version is the one that must be displayed.
     */
    abstract public function create(int $userId, NewForeignTask $task): ForeignTask;

    /**
     * Moves the task to another state over there.
     *
     * The most common single act there is, and the only one that happens from
     * the merged list itself: somebody ticks a task off. It gets its own
     * operation for the same reason the calendar gives dragging its own
     * `move()` instead of a general "change everything" — whoever ticks a task
     * off does not want title, description and due date sent along, least of
     * all in whatever version this application happens to hold in memory.
     *
     * `$status` is the OTHER system's word, untranslated. "todo" means "todo"
     * there; bending it to a local vocabulary would be a claim about foreign
     * data, and the list already shows foreign states unchanged for exactly
     * that reason.
     *
     * There is no general `update()`, and that is not an omission. Everything
     * beyond the state belongs where the task lives — that is what the task's
     * own `url` is for. A form here that edits foreign fields would need to
     * know the other system's rules, and it would learn them the slow way.
     *
     * @throws ForeignSourceFailed
     */
    abstract public function changeStatus(int $userId, string $taskId, string $status): ForeignTask;

    /**
     * Deletes the task over there.
     *
     * No return value: what no longer exists cannot return anything. A failure
     * throws — quietly doing nothing would be worst here, because the interface
     * has already removed the row.
     *
     * @throws ForeignSourceFailed
     */
    abstract public function delete(int $userId, string $taskId): void;

    /**
     * May this person create, change and delete here right now?
     *
     * ONE switch for all three operations, not three. Separate rights would be
     * three checks, and somebody forgets the third — the same reasoning that
     * makes this a class of its own rather than a flag on `TaskSource`.
     *
     * Separate from `isAvailable()`: a source can be reachable and still not
     * writable — when the target system does not offer the create operation, or
     * when the acting person has no rights over there. An interface should then
     * not show the button in the first place.
     */
    public function isWritable(): bool
    {
        return true;
    }
}
