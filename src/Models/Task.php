<?php

namespace Peppermint\Tasks\Models;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        return null;
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
