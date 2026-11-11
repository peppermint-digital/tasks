<?php

namespace Peppermint\Tasks\Sources;

use RuntimeException;

/**
 * Another system's tasks — shown, never taken over.
 *
 * ## What is here and what is not
 *
 * Here are the mechanics: ask, map the answers, tell an outage from an empty
 * list, resolve the person, pass the remaining fields through. They are the
 * same in every application. The WAY to the other system is not here but
 * behind {@see RemoteTaskSystem} — AI Brain calls its connected products
 * through its own gateway, a product through the bridge.
 *
 * This class therefore knows **no single system**. It does not know that there
 * is a Manager, and it knows none of its field names. The moment it knew one,
 * it would be the special-purpose client again that the shared version
 * replaced — three of them existed, one per product.
 *
 * ## No sync, no copy
 *
 * The tasks still belong to the other system. A copy here would be a second
 * truth needing reconciliation, and the question of which one wins would have
 * no good answer. What comes back is a {@see ForeignTask} — no row in this
 * database, no migration, nothing to clean up. The same stance the calendar
 * takes with foreign events.
 *
 * ## Reading stays quiet — but not silent
 *
 * An outage while reading throws a `RuntimeException`; the registry logs it and
 * leaves the source out, so the rest of the list stays visible. That is right
 * for a page that draws a list and wrong for a form, which is why
 * {@see TaskSourceRegistry::harvest()} catches the same throw and puts the
 * reason in its answer. Same mechanics, two readings — the caller picks.
 *
 * ## Read-only, deliberately, in this round
 *
 * Extends {@see TaskSource} and not {@see WritableTaskSource}: this is the
 * reading half. The writing half exists in the package's contract already and
 * can be added here later; building it along the way would mean shipping a
 * write path into other people's systems that nothing has exercised.
 */
class RemoteTaskSource extends TaskSource
{
    /**
     * @param  int  $rows  How many rows to ask each system for, explicitly.
     *
     * ## Why the number is named and not left out
     *
     * Without the argument every one of the three products answers with 50
     * (measured 21.10.2026, #8471) — and a list that stops at 50 is not a long
     * list, it is a wrong one: nothing says it was cut.
     *
     * A constructor parameter and not a config key, because the key would
     * belong to one application and this package has four. Whoever wants it
     * configurable passes `config(...)` in when registering.
     *
     * Known gap, not this package's to fix: the Verwaltung ignores `limit`
     * quietly — 2 asked for, 8 delivered (Bug #1406, peppermint-verwaltung).
     * A caller must therefore not read "exactly `rows` rows came back" as
     * "there are exactly that many".
     * @param  bool  $openOnly  Ask only for what is still open.
     *
     * ## Why this BELONGS with the raised limit
     *
     * Measured on the live Manager: without a limit, 50 rows came back; with
     * one, 470 — and **438 of those were completed**, 8 cancelled. 24 were
     * open. So the 50 were not merely incomplete, they were mostly history.
     *
     * Raising the limit alone would therefore have poured 438 finished foreign
     * rows into the view somebody opens to see what to do next. The list would
     * have become complete and useless in the same step.
     *
     * A completed foreign task carries no action. Whoever needs one asks the
     * product itself.
     */
    public function __construct(
        protected readonly RemoteTaskSystem $remote,
        protected readonly int $rows = 500,
        protected readonly bool $openOnly = true,
    ) {}

    public function key(): string
    {
        return $this->remote->key();
    }

    public function label(): string
    {
        return $this->remote->label();
    }

    /**
     * Pure presentation, and therefore the application's business: the colour
     * belongs to the interface, not to the communication layer. With no entry
     * the display falls back on its own default — which is what `null` means
     * on {@see TaskSource::colour()}.
     */
    public function colour(): ?string
    {
        $colour = function_exists('config') ? config('tasks.source_colours.'.$this->key()) : null;

        return is_string($colour) ? $colour : null;
    }

    /**
     * The source is available if the system hands out its tasks at all.
     *
     * Measured, not configured. A system that does not offer `tasks.list` never
     * appears as a source — no empty section, no dead filter chip, nothing to
     * pick in a form. And if it loses the tool later, the source disappears by
     * itself; that is the answer to "not switched on", and it needs no second
     * place to maintain.
     *
     * Asked every time rather than cached: access can be withdrawn between two
     * page loads, and a remembered "yes" shows a section that then cannot be
     * filled.
     */
    public function isAvailable(): bool
    {
        return $this->remote->offers('tasks.list');
    }

    /**
     * This person's open tasks over there.
     *
     * @return array<int, ForeignTask>
     */
    public function tasks(int $userId): array
    {
        // Without a resolvable person, no foreign tasks — fail-closed, and here
        // rather than at the other end. An argument that expires when the
        // identity is missing makes the answer WIDER instead of narrower; that
        // was Bug #657 in the calendar, and in a task list it would mean
        // handing somebody else's tasks to whoever is logged in.
        if ($this->remote->identify($userId) === null) {
            return [];
        }

        $result = $this->remote->call('tasks.list', $this->arguments());

        if (! $result->ok) {
            // An outage is not an empty result. The registry treats the throw
            // as an outage: logged and left out by `collect()`, named in the
            // answer by `harvest()`.
            throw new RuntimeException($this->label().' nicht erreichbar: '.$result->message);
        }

        $tasks = [];

        foreach ($result->rows() as $row) {
            $tasks[] = $this->toForeignTask($row);
        }

        return $tasks;
    }

    /**
     * The other system's task kinds, so a person can pick one instead of
     * guessing.
     *
     * Kinds are product-owned — this package knows neither `ticket` nor
     * `routine`. Does the other system not offer the operation, the list is
     * empty and the caller has to rely on its default. Guessing would be the
     * worse answer: a wrong kind files the task in the wrong drawer, and that
     * only shows up over there.
     *
     * All three products offer `tasks.kinds.list` as of 11.11.2026 — the gap
     * from #5828 (the calendar had `calendar.kinds.list`, tasks had no
     * counterpart) is closed, and anything in the codebase still claiming "no
     * product offers it" is out of date.
     *
     * No `creatable` and no `forbids`, unlike `calendar.kinds.list`: the task
     * tools do not send them. They are not filtered on absence either — a
     * missing field is not a "no", and treating it as one would empty the list
     * for all three products at once.
     *
     * @return array<int, ForeignKind>
     */
    public function kinds(): array
    {
        if (! $this->remote->offers('tasks.kinds.list')) {
            return [];
        }

        $result = $this->remote->call('tasks.kinds.list');

        if (! $result->ok) {
            // Quiet, unlike `tasks()`. A missing list of kinds costs a form its
            // preselection; the caller falls back on the target system's
            // default, which is a worse form and not a wrong one. Taking the
            // whole source down for it would be the larger damage.
            return [];
        }

        $kinds = [];

        foreach ($result->rows() as $row) {
            if (! isset($row['key'])) {
                continue;
            }

            $kinds[] = new ForeignKind(
                key: (string) $row['key'],
                label: (string) ($row['label'] ?? $row['kind_label'] ?? $row['key']),
                // The required fields travel along so a form can ask for them
                // up front instead of showing the error after sending — the
                // Manager's `routine` kind requires `assigned_to`.
                requires: array_values(array_filter((array) ($row['requires'] ?? []), 'is_string')),
            );
        }

        return $kinds;
    }

    /**
     * What is sent with every `tasks.list`.
     *
     * Sent to ALL systems, even those that do not carry the arguments in their
     * schema: the CRM and the Verwaltung discard unknown arguments quietly
     * (checked 21.10.2026 — same answer with and without, no error) and only
     * deliver open items of their own accord. A system that learns the
     * arguments later benefits without a change here.
     *
     * @return array<string, mixed>
     */
    protected function arguments(): array
    {
        return array_filter([
            'limit' => $this->rows,
            'active_only' => $this->openOnly ?: null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * The address this task lives at in its own system.
     *
     * **Only what the product itself says.** Only it knows its routes.
     *
     * There used to be a fallback beside this — the product's web address plus
     * `/tasks/{id}`, built in the hour when no product sent an address along. It
     * was right for the Manager and wrong for the other two: the Verwaltung's
     * tasks are TICKETS and live under `/tickets/{id}`, and the CRM had no task
     * page at all for a while. It was only noticed by accident, because both
     * default to the asking person's own tasks and that person happened to have
     * none.
     *
     * So nothing is guessed any more. A system that sends no address gets no
     * link, and the interface shows text. That is the more honest answer: a link
     * to a 404 looks like a working route and costs the reader the detour before
     * they find out it was not one.
     *
     * All three products send their address, correct per product — `pm…/tasks/1364`,
     * `vw…/tickets/40`, `crm…/tasks/1` (measured 11.11.2026).
     *
     * @param  array<string, mixed>  $row
     */
    protected function url(array $row): ?string
    {
        $own = trim((string) ($row['url'] ?? ''));

        return $own === '' ? null : $own;
    }

    /**
     * Everything else the other system sends along.
     *
     * ## Why nothing is enumerated here
     *
     * Three fields used to stand here by name — `project`, `assigned_to`,
     * `estimated_hours` — and everything else fell away silently. The CRM was
     * already sending `category` at the time, the Verwaltung `ticket_number`
     * and `customer`; none of it was visible in the list, and nothing reported
     * anything (#5830).
     *
     * That is a known class of bug: whoever enumerates fields instead of
     * passing them through breaks again with every new field — and QUIETLY. The
     * tool reports success, the interface still does not show it, and the
     * search then happens at the wrong end.
     *
     * So the other way round: what this task carries as a field of its own is
     * consumed, the rest travels on. A product that sends something new
     * tomorrow needs no change here.
     *
     * ## Why only flat values
     *
     * `extra` is displayed, not evaluated. A nested structure arrives in the
     * row as "[object Object]". The two cases that exist today — `project` and
     * `assigned_to` as an object with `name` — are therefore flattened
     * explicitly; anything else has to be a scalar or stays out.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function extra(array $row): array
    {
        // What ForeignTask carries itself. Showing it twice would mean having
        // the same thing twice in the same row.
        //
        // This list is also what keeps a foreign field from overwriting a core
        // one: unlike the calendar's `ExternalEvent`, `ForeignTask` does not
        // throw colliding keys away, so they must not be put in in the first
        // place.
        $consumed = [
            'id', 'title', 'status', 'priority', 'due_date',
            'urgency_score', 'url', 'kind', 'kind_label',
        ];

        $rest = [];

        foreach ($row as $field => $value) {
            if (in_array($field, $consumed, true) || $value === null || $value === '') {
                continue;
            }

            // The two known objects: the name is what matters, not the row
            // behind it.
            if (is_array($value)) {
                if (isset($value['name']) && is_scalar($value['name'])) {
                    $rest[$field] = (string) $value['name'];
                }

                continue;
            }

            if (is_scalar($value)) {
                $rest[$field] = $value;
            }
        }

        return $rest;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function toForeignTask(array $row): ForeignTask
    {
        return new ForeignTask(
            sourceKey: $this->key(),
            id: $row['id'] ?? 0,
            title: (string) ($row['title'] ?? '(ohne Titel)'),

            // The other system's word, untranslated. "todo" means "todo" there;
            // bending it to "open" here would be a claim about foreign data.
            status: isset($row['status']) ? (string) $row['status'] : null,
            priority: isset($row['priority']) ? (string) $row['priority'] : null,

            // Not every product has one: the Manager and the CRM send
            // `due_date`, the Verwaltung does not — a ticket there has no due
            // date. "If present, then with it", rather than a field in the wire
            // format that nothing fills.
            dueDate: isset($row['due_date']) ? (string) $row['due_date'] : null,

            // The number travels, the breakdown does not: its factors refer to
            // time blocks, blockers and comments that do not exist here.
            urgency: isset($row['urgency_score']) ? (float) $row['urgency_score'] : null,

            url: $this->url($row),

            // The other system's kind, untranslated like the status. Without it
            // the shared list cannot filter its foreign half, and "I still have
            // three Verwaltung items open" stays unanswerable.
            kind: isset($row['kind']) ? (string) $row['kind'] : null,
            kindLabel: isset($row['kind_label']) ? (string) $row['kind_label'] : null,

            extra: $this->extra($row),
        );
    }
}
