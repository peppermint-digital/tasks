<?php

namespace Peppermint\Tasks\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Peppermint\Tasks\Exceptions\ForbiddenAttributeForKind;
use Peppermint\Tasks\Kinds\KindRegistry;
use Peppermint\Tasks\Kinds\TaskKind;
use Peppermint\Tasks\Priority\PriorityRegistry;
use Peppermint\Tasks\Priority\TaskPriority;
use Peppermint\Tasks\Status\StatusRegistry;
use Peppermint\Tasks\Status\TaskStatus;
use Peppermint\Tasks\Urgency\UrgencyEngine;

/**
 * The shared core of a task.
 *
 * Ring 1 — what a task needs to exist: `title`, `status`.
 * Ring 2 — the core standard, measured against iCalendar VTODO: description,
 *          due date, completion, hierarchy, assignee, priority.
 * Ring 3 — everything else lives in the application, never here. A new column
 *          in this table travels into every other product that will never need
 *          it.
 *
 * Products extend this class; they do not copy it. A copied model drifts from
 * the first fix onwards.
 */
class Task extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('tasks.tables.tasks', 'tasks');
    }

    protected static function booted(): void
    {
        // The guard that keeps a kind from decaying into a label.
        //
        // On `saving` and not in a request rule, because the rule only holds
        // for the path that carries it. The Manager stated "a routine cannot be
        // completed" in two controllers; a third caller — the MCP tool — never
        // learned about it. Stated here it holds for every writer, including
        // the ones written next year.
        static::saving(function (self $task) {
            $kind = $task->kindDefinition();

            if ($kind === null) {
                return;
            }

            foreach ($kind->forbiddenAttributes() as $field) {
                $column = static::column($field);

                // Only a value that is actually being set counts. A column that
                // is simply absent, or explicitly emptied, is not a violation —
                // clearing a field the kind forbids is the correct move.
                if ($task->getAttribute($column) !== null) {
                    throw ForbiddenAttributeForKind::make($kind->key(), $field);
                }
            }

            $erlaubt = $kind->statusKeys();
            $status = static::scalar($task->field('status'));

            if ($erlaubt !== null && $status !== null && ! in_array($status, $erlaubt, true)) {
                throw ForbiddenAttributeForKind::make($kind->key(), 'status='.$status);
            }
        });
    }

    /**
     * The real column behind a package field name.
     *
     * This is what lets the Manager keep its grown column names while the
     * package speaks its own — without it, adopting an existing product means
     * renaming its columns, and then nobody adopts anything.
     */
    public static function column(string $field): string
    {
        return config("tasks.columns.tasks.{$field}", $field);
    }

    /** Reads a package field through whatever the column is actually called. */
    public function field(string $name): mixed
    {
        return $this->getAttribute(static::column($name));
    }

    /**
     * The registered definition of this task's state — or null if the
     * application never registered it.
     *
     * Null rather than an exception on purpose: a task whose status fell out
     * of the vocabulary must stay readable. A product that renames a state and
     * forgets one row should see a task without a weight, not a page that
     * cannot be opened.
     */
    public function statusDefinition(): ?TaskStatus
    {
        $key = static::scalar($this->field('status'));

        if ($key === null) {
            return null;
        }

        $registry = app(StatusRegistry::class);

        return $registry->has($key) ? $registry->get($key) : null;
    }

    /**
     * The registered kind of this task — or null when the application has no
     * kinds, or this row carries one nobody registered.
     *
     * Null rather than an exception, for the same reason as the status: a row
     * whose kind fell out of the vocabulary must stay readable. A product that
     * renames a kind and misses one row should see a task without a kind, not a
     * page that cannot be opened.
     */
    public function kindDefinition(): ?TaskKind
    {
        $key = static::scalar($this->field('kind'));

        if ($key === null) {
            return null;
        }

        $registry = app(KindRegistry::class);

        return $registry->has($key) ? $registry->get($key) : null;
    }

    public function priorityDefinition(): ?TaskPriority
    {
        $key = static::scalar($this->field('priority'));

        if ($key === null) {
            return null;
        }

        $registry = app(PriorityRegistry::class);

        return $registry->has($key) ? $registry->get($key) : null;
    }

    /**
     * The stored key behind a field, whatever shape the product keeps it in.
     *
     * A product casting its status column to a backed enum is entirely normal
     * — and then the field does not hand back a string. Without this, adopting
     * a grown application fails at the first read, which is exactly the
     * situation the package is supposed to handle.
     */
    protected static function scalar(mixed $value): ?string
    {
        $key = $value instanceof BackedEnum ? (string) $value->value : $value;

        return ($key === null || $key === '') ? null : (string) $key;
    }

    /** Finished, one way or the other. Unknown state counts as not finished. */
    public function isTerminal(): bool
    {
        return $this->statusDefinition()?->isTerminal() ?? false;
    }

    /**
     * Beschraenkt die Dringlichkeit auf bestimmte Faktoren. Null heisst: alle.
     *
     * Fuer Aufgaben, die nicht im selben Rennen laufen sollen wie echte Arbeit
     * — wiederkehrende Routinen etwa. Sie kommen ohnehin wieder; wuerden sie
     * normal gewichtet, stuenden sie mit Alter und Prioritaet dauerhaft in der
     * Liste und verdraengten, was einmal zu tun ist.
     *
     * Eine Anwendung ueberschreibt das und nennt die Faktoren, die trotzdem
     * zaehlen sollen — typischerweise den Handgriff, mit dem ein Mensch eine
     * Routine fuer heute doch nach oben holt.
     *
     * @return array<int, string>|null
     */
    public function restrictUrgencyTo(): ?array
    {
        // The kind answers first, because the restriction is a statement about
        // the sort of work, not about this one row. An application may still
        // override this for something only the single task knows.
        return $this->kindDefinition()?->restrictUrgencyTo();
    }

    public function urgency(): float
    {
        return app(UrgencyEngine::class)->score($this);
    }

    /**
     * @return array{total: float, factors: array<string, array{label: string, value: float}>}
     */
    public function urgencyBreakdown(): array
    {
        return app(UrgencyEngine::class)->breakdown($this);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, static::column('parent_id'));
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, static::column('parent_id'));
    }

    /**
     * Reminders someone set on this task — several, dismissible, with history.
     *
     * Deliberately separate from `due_date`: that says when the work must be
     * done, this says when someone wants to be spoken to about it. See
     * {@see TaskReminder}.
     *
     * ## Why not simply `reminders()`
     *
     * It was, for half a day. `reminders` is a name products already use with
     * their own meaning — the Peppermint CRM has carried a polymorphic one
     * since 07/2025 that hangs off contacts and companies too, and computes
     * its moment RELATIVE to the due date.
     *
     * A package that claims a common name AND pins its return type makes that
     * impossible: overriding `reminders(): HasMany` with `MorphMany` is not a
     * refinement, it is incompatible, and PHP aborts while LOADING the class —
     * not at call time. The product is then locked out of the feature for a
     * reason that has nothing to do with the feature.
     *
     * The package is the newcomer here. It takes the unambiguous name and
     * leaves the common one to the products.
     */
    public function taskReminders(): HasMany
    {
        return $this->hasMany(TaskReminder::class, 'task_id');
    }

    /** Only the ones still waiting to be acted on. */
    public function activeTaskReminders(): HasMany
    {
        return $this->taskReminders()->where('is_dismissed', false);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(config('tasks.user_model'), static::column('assigned_to'));
    }

    /**
     * Everything still to do, in this application's vocabulary.
     *
     * Enumerated, not negated: a row carrying a status nobody registered is a
     * data problem, and "not terminal" would sweep it into the open list where
     * it looks like ordinary work. See StatusRegistry::openKeys().
     */
    public function scopeOpen($query)
    {
        return $query->whereIn(static::column('status'), app(StatusRegistry::class)->openKeys());
    }
}
