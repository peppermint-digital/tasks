<?php

namespace Peppermint\Tasks\Sources;

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;

/**
 * A source in whose system you may also PLAN TIME on a task.
 *
 * ## Why this is an additional contract and not three methods on
 * ## `WritableTaskSource`
 *
 * Adding abstract methods to an interface breaks its implementations. That is
 * not a danger in the abstract: `WritableTaskSource` has one in AI Brain
 * (`app/Tasks/Sources/ConnectedProductTaskSource.php`), and a product that
 * updates this package would stop loading. The same trap and the same answer
 * are written down for the mail toolbar — "instead an ADDITIONAL contract,
 * asked by `instanceof`".
 *
 * It is also the honest shape, not merely the safe one. Writing a task and
 * planning time on one are two facts about a product, and they genuinely come
 * apart: a system can accept a new task and have no calendar to put a block
 * in, and it can hold blocks for tasks that may not be created from outside at
 * all — the Verwaltung is exactly that case, with `UpdateTaskTool` and
 * `DeleteTaskTool` but no `CreateTaskTool`.
 *
 * ## The price, and the test that pays it
 *
 * An optional contract breaks nothing — **it just goes missing**. In AI Brain
 * `BrainWerkzeugquelle` failed to implement the mail toolbar's search contract
 * and nobody noticed: the masks opened, the result list stayed empty. Whoever
 * introduces such a contract writes the guard test that walks EVERY registered
 * implementation along with it (`tests/Feature/PlanbareQuellenTest.php`).
 *
 * Without that test this decision is an opinion.
 *
 * ## Why the operation token IS the capability name
 *
 * `WritableTaskSource::supports()` takes its own words (`create`, `change`,
 * `delete`) and a source maps them onto whatever it calls over the wire. That
 * works there because the mapping is one line in each implementation.
 *
 * Here there is nothing to map onto: the three capability names are fixed, they
 * are what the hub's register is asked for, and they are what sits in saved
 * decisions in `gateway_grants`. A second vocabulary beside them would be two
 * lists of three strings that have to stay in step — and the one that is not
 * read by the register is the one that drifts without a symptom.
 *
 * ## Why the names look like this
 *
 * `<topic>[.<subtopic>].<verb>`, the form all 79 capability names in the hub's
 * register follow. Three things rule out the shorter candidates:
 *
 * - **No verbless name.** In the register only READING names get away without
 *   a verb (`inbox.entries`, `mailbox.actions`). A verbless `tasks.schedule`
 *   could not gain a second operation without being renamed, and the name sits
 *   in stored grants.
 * - **Not `tasks.time.*`**: `time.create` and `time.timers.*` already mean
 *   TRACKED time. `tasks.time.create` reads as "book time against a task" — a
 *   different operation that exists.
 * - **Not `tasks.blocks.*`**: "blocks" is taken. `ListTasksTool` in the Manager
 *   answers `blocked_by_incomplete` — the tasks holding a task up.
 *
 * And none of the three may contain one of the eight read markers (`.list`,
 * `.show`, `.search`, `.get`, `list-`, `show-`, `search-`, `get-`):
 * `ProductGateway::readShaped()` derives from the NAME whether an operation is
 * reading, and two things hang off that — a writing operation demands a
 * resolvable acting person, and it is never retried. Both are exactly right
 * for planning, and both are lost if the name looks like a read.
 *
 * `tasks.schedule.*` follows the nearest existing precedent character for
 * character: `tasks.reminders.create` is the same thing — a sub-resource of a
 * task, created from another system, under the `tasks.` roof.
 *
 * ## What this contract does NOT decide
 *
 * **Who may touch a block.** That is the answering system's policy, asked over
 * the acting person the comm layer carries along. This package keeps no second
 * rights list — `RemoteTaskSystem` says why: "A second rights list here would
 * age without anyone noticing it had."
 *
 * **Where the block lives.** In the database of the system that owns the task,
 * never locally. A `PlannedBlockProfile` points at a LOCAL Eloquent model
 * (`calendar/src/Models/PlannedBlockProfile.php:41-44`), and a foreign task has
 * neither model nor row here. A locally created block would have to leave
 * `task_id` empty, and `PlannedBlockKind::saving()` throws for exactly that.
 *
 * **The kind of the block.** Not the caller's choice: every system has exactly
 * one task-block kind (`manager_task`, `crm_task`, `verwaltung_ticket`), and
 * the answering tool takes its own. Asking for one would invite the caller to
 * name one, and a wrongly named kind files the thing in the wrong drawer.
 */
interface PlansForeignTasks
{
    /** Plan time on a task over there. */
    public const CAPABILITY_CREATE = 'tasks.schedule.create';

    /** Move a block that is already there — see {@see move()} on why this is one operation. */
    public const CAPABILITY_UPDATE = 'tasks.schedule.update';

    /** Give the time back. */
    public const CAPABILITY_DELETE = 'tasks.schedule.delete';

    /**
     * The whole set — so a caller can check against it instead of repeating
     * three strings, and so the registry has one place to reject a typo.
     *
     * Same reasoning as `SourceFailure::REASONS`, and deliberately in the same
     * shape: three capabilities, not one. The Verwaltung is why — it has
     * `UpdateTaskTool` and `DeleteTaskTool` and no `CreateTaskTool`, and a
     * single switch would make it either entirely plannable or not at all.
     */
    public const CAPABILITIES = [
        self::CAPABILITY_CREATE,
        self::CAPABILITY_UPDATE,
        self::CAPABILITY_DELETE,
    ];

    /**
     * Does the OTHER SYSTEM offer this operation at all?
     *
     * A fact about that product, not a decision about a person — the same seam
     * `WritableTaskSource::supports()` was split along, and for the same
     * measured reason: `isWritable()` had quietly taken on two jobs there, and
     * under it the Verwaltung was never writable, not even for ticking off.
     *
     * Measured rather than configured, like {@see TaskSource::isAvailable()}:
     * loses the other side the capability, or is the grant withdrawn, the
     * operation disappears by itself — nobody has to remember a switch.
     *
     * @param  string  $capability  one of {@see CAPABILITIES}
     */
    public function offers(string $capability): bool;

    /**
     * Plans a span of time on a task over there and returns the block the
     * other system made of it.
     *
     * Its version, not ours — the same rule as `WritableTaskSource::create()`:
     * "returns what the other system made of it, not what we sent". It applies
     * its own kind, derives title and category from its own task, and attaches
     * its own identifier, and its version is the one that must be displayed.
     *
     * `$taskId` is the identifier of the task IN ITS OWN SYSTEM. And it is
     * called a task id and not a ticket id or a subject id, although the
     * Verwaltung plans tickets: its own writing task tool takes `task_id` and
     * finds a `Ticket` with it ("Die Kennung des Tickets in DIESEM System",
     * `UpdateTaskTool.php:65`). The `tasks.*` vocabulary already covers the
     * difference; a second name would be a translation in the one place this
     * package may not have one.
     *
     * `$userId` is the person whose time is being planned, in THIS system's
     * ids. It does not travel on the wire — see {@see TimeSpan} for what does
     * and, more importantly, what does not.
     *
     * @throws ForeignSourceFailed when the other system did not do it
     */
    public function plan(int $userId, string|int $taskId, TimeSpan $span): PlannedBlock;

    /**
     * Moves a block that already exists over there.
     *
     * ONE operation for "change" and "move", because a block has beginning,
     * end and person and nothing else to argue about. That is not a new
     * decision but twice the same one already taken:
     *
     * - `WritableEventSource::move()` in the calendar: "Der haeufigste Eingriff
     *   ueberhaupt … Deshalb ein eigener Vorgang und kein ‚aendere alles': Wer
     *   eine Stunde nach hinten zieht, will nicht, dass dabei Titel,
     *   Teilnehmer und Serie mitgeschickt werden."
     * - The hub's own register, at the calendar's update entry: "Verschieben
     *   laeuft ueber `update`. Vollstaendiges Aendern bleibt dem
     *   Eigentuemersystem, dessen Maske die Felder kennt, die hier niemand hat
     *   (#5729)."
     *
     * And it is the reason `WritableTaskSource` has no general `update()`
     * either.
     *
     * `$blockId` is the identifier of the BLOCK in its own system — never a
     * local `calendar_events.id`. Nothing of ours is addressable over there.
     *
     * @throws ForeignSourceFailed when the other system did not do it
     */
    public function move(int $userId, string|int $blockId, TimeSpan $span): PlannedBlock;

    /**
     * Gives the planned time back.
     *
     * Named `unplan` and not `delete` on purpose: `WritableTaskSource::delete()`
     * deletes the TASK, and these two sit next to each other on the same
     * source. "Delete" on a planning contract is one hasty reading away from
     * removing the wrong thing.
     *
     * Why this operation has to exist at all: whoever planned an hour wrongly
     * is sitting in THIS system. Without it they would have to change systems
     * to take it back — and until they do, the grid shows time as planned that
     * is free. That is the state `PlannedBlockKind` avoids with "Kein
     * Papierkorb" (`calendar/src/Kinds/PlannedBlockKind.php:49-51`).
     *
     * No return value: what no longer exists cannot return anything, the same
     * as `WritableTaskSource::delete()`. A failure throws — quietly doing
     * nothing would be worst here, because the interface has already taken the
     * block out of the grid.
     *
     * @throws ForeignSourceFailed when the other system did not do it
     */
    public function unplan(int $userId, string|int $blockId): void;
}
