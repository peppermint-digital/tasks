<?php

namespace Peppermint\Tasks\Sources;

/**
 * Another system that offers tasks — as far as this package needs to know it.
 *
 * ## Why a contract and not an abstract source
 *
 * The mechanics of turning another system's tasks into a readable list are the
 * same in every application: ask, translate, tell an outage from an empty
 * answer, resolve the person. The WAY there is not — AI Brain calls its
 * connected products through its own gateway, a product calls through the
 * bridge (`AiBrain::gateway(...)`), and whoever has neither calls something
 * else.
 *
 * Were the way inside the source, the source would exist twice. Were the hub's
 * types inside the package (`ConnectedProduct`, `ProductGateway`), every other
 * product would carry hub fields along — and that is the worse duplication. So:
 * the mechanics here, the way at the host. The calendar package arrived at the
 * same split for the same reason, and the names here mirror it deliberately.
 *
 * ## The four questions only the host can answer
 *
 * 1. **What is the system called** (`key`, `label`) — the key sits in every
 *    task identifier ("manager:7"), in the kind keys ("crm:vorgang") and in
 *    users' saved filter choices. It is therefore stored form and must not
 *    change.
 * 2. **What does it offer** (`offers`) — measured against a capability
 *    register, not guessed. Does the product lose a tool, the source
 *    disappears by itself instead of running into an error message.
 * 3. **Who is this person over there** (`identify`) — two systems, two user
 *    tables. Without resolution the operation does NOT take place.
 * 4. **Fetch it** (`call`) — the one call out of the house.
 *
 * ## What is NOT asked here: whether this person may
 *
 * There is no `mayWrite()`, and in this round there is no write path at all —
 * {@see RemoteTaskSource} extends the read-only {@see TaskSource}.
 *
 * But the absence of a rights question also holds for READING, and that is the
 * decision worth stating: **the permission check lives in the other system.**
 * It owns its tasks, it knows which of them are private, and it is the only
 * place that can still be right after somebody over there changes a rule. A
 * second rights list here would age without anyone noticing it had.
 *
 * So the rule this package follows is: ask in the name of a NAMED person
 * (`identify`), and let the target system's policy decide what that person
 * gets to see.
 *
 * ### Why this is written out instead of assumed
 *
 * Because it has gone wrong twice, and both times quietly (Wiki #595,
 * 01.11.2026):
 *
 *   - The Manager's `/emails/search-tasks` handed out EVERY matching task,
 *     private ones included.
 *   - The Verwaltung's `mailbox/ticket-search` asked `canEdit()` — the mail
 *     browser's gate — instead of "may this person read tickets".
 *
 * Both were harmless for as long as they were only reachable inside their own
 * product. A source like this one makes them reachable from outside, and that
 * is the moment a lax check becomes a leak. Whoever implements this contract
 * for a product is implementing the half that trusts; the other half has to be
 * worth trusting.
 *
 * ## And what an empty answer means
 *
 * Nothing about permissions. An empty list means "this person has no open
 * tasks there", never "this person may see none" and never "the system is
 * down". The second of those is the reason {@see TaskHarvest} exists: an
 * outage belongs in the ANSWER, not only in the log.
 */
interface RemoteTaskSystem
{
    /**
     * Stable key, lower case, without the vendor prefix.
     *
     * It sits in the task identifiers, in the kind keys and in saved filters —
     * a rename makes users' filters quietly wrong without anything breaking.
     * So: choose once, then leave it.
     */
    public function key(): string;

    /** What a human reads on the filter chip and at the task. */
    public function label(): string;

    /**
     * Does the system offer this operation?
     *
     * The names are the package's: `tasks.list` and `tasks.kinds.list` for
     * reading. Measured against a capability register, not configured — a
     * product that loses the tool loses the source, and nobody has to remember
     * to switch it off.
     */
    public function offers(string $capability): bool;

    /**
     * This person's identifier in the other system — or `null`.
     *
     * `null` means: the operation does not take place. The list stays empty.
     *
     * That direction is not interchangeable. The calendar's Bug #657 was an
     * argument that EXPIRED when the identity was missing, which made the
     * answer WIDER instead of narrower: an unknown person got the whole team
     * calendar. The same shape of mistake in a task list hands somebody else's
     * tasks to whoever is logged in — so here a missing identity means an
     * empty answer, always.
     */
    public function identify(int $userId): ?string;

    /**
     * Trigger an operation over there.
     *
     * Does NOT throw. An unreachable system is the normal case and not an
     * exceptional state — whoever throws here forces every caller into a
     * `try`, and one of them forgets.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $capability, array $arguments = []): RemoteResult;
}
