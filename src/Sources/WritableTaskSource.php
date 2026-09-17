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
    public const CREATE = 'create';

    public const CHANGE = 'change';

    public const DELETE = 'delete';

    /**
     * Does the OTHER SYSTEM offer this operation at all?
     *
     * ## Why this is separate from `isWritable()`, and why that is not a
     * ## contradiction of the calendar
     *
     * The calendar argues for ONE switch instead of three, and the argument is
     * good: separate RIGHTS are three checks, and somebody forgets the third.
     *
     * But this method does not ask about rights. It asks what the target system
     * OFFERS — a fact about that product, not a decision about a person. And
     * that fact legitimately varies per operation:
     *
     *   The Verwaltung's only task kind is a ticket, and
     *   `TicketArt::isUserCreatable()` says false: a ticket exists because
     *   somebody wrote in, always with a concern behind it. Creating one from
     *   another application's "what would you like to create?" dialogue is a
     *   question with no useful answer. Changing and deleting, on the other
     *   hand, make perfect sense — ticking a ticket off from a merged list is
     *   the most common wish there is.
     *
     * The first version of this contract (v0.8.0) demanded all three together.
     * Under it the Verwaltung was never writable, not even for ticking off. The
     * flaw was not the one-switch rule; it was that `isWritable()` had quietly
     * taken on TWO jobs — asking the product what it can do, and asking whether
     * this person may. Splitting along that seam keeps the calendar's rule
     * where it belongs and lets the fact be a fact.
     *
     * A source that offers everything does not need to implement this.
     *
     * @param  string  $operation  one of CREATE, CHANGE, DELETE
     */
    public function supports(string $operation): bool
    {
        return true;
    }

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

    /**
     * May this person perform THIS operation here right now?
     *
     * Both questions at once, in the one place that knows they belong together:
     * the person has to be allowed to write (`isWritable()`), and the target
     * system has to offer the operation (`supports()`).
     *
     * An interface asks this and nothing else. That is what keeps the
     * calendar's warning answered — whoever repeats the pair by hand at every
     * control eventually gets one of them wrong, and the button that then
     * appears fails only when somebody presses it.
     */
    public function allows(string $operation): bool
    {
        return $this->isWritable() && $this->supports($operation);
    }
}
