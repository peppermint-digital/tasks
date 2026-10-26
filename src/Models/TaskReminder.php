<?php

namespace Peppermint\Tasks\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Peppermint\Reminders\Models\Reminder;

/**
 * A reminder a person set for themselves on a task.
 *
 * ## Why this is now a subclass
 *
 * Everything this class used to be — the store, the scopes the delivery runs,
 * the history, the idempotent dismissal — says nothing about tasks. It said
 * `task_id` and nothing else did.
 *
 * When appointments wanted the same thing (10/2026), the choice was a second
 * table with a second delivery run, or one store with a subject reference.
 * Two stores is the shape of the bug: the Peppermint CRM had a delivery that
 * queried one of its two reminder tables, and the rows in the other reached
 * nobody for weeks (Bug #880).
 *
 * So the store moved to `peppermint/reminders` and this is what stayed: the
 * task's half of it. The product inherits, it does not copy — a copied model
 * drifts from the first fix onwards.
 *
 * ## Why the table name still comes from `tasks`
 *
 * Four products are already running this table, and one of them does NOT call
 * it `task_reminders`: the Peppermint Verwaltung maps it to `ticket_reminders`,
 * because there the tickets ARE the tasks. Reading the name from the reminders
 * package instead would point this model at a table that does not exist there.
 *
 * The provider keeps the two configs in step — see
 * {@see \Peppermint\Tasks\TasksServiceProvider::uebernimmTabellenname()}.
 *
 * ## What `task_id` is now
 *
 * Both are filled: `task_id` for the foreign key and its cascade, the subject
 * pair for everything that does not care what it is reminding about. That is
 * two references to one fact, which is a smell — and it is the deliberate
 * price of a reversible migration. `task_id` keeps its values so the way back
 * stays a config change; dropping it is a separate, later decision.
 */
class TaskReminder extends Reminder
{
    /**
     * Resolvable, and from THIS package's config — see the note above about
     * `ticket_reminders`.
     */
    public function getTable(): string
    {
        return (string) config('tasks.tables.task_reminders', 'task_reminders');
    }

    /**
     * Likewise: a product that renamed columns did so in `tasks`, before this
     * package existed. `Reminder` calls this as `static::`, so the override
     * reaches its scopes.
     */
    public static function column(string $field): string
    {
        $mapping = config('tasks.columns.task_reminders', []);

        return is_array($mapping) ? (string) ($mapping[$field] ?? $field) : $field;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * Keep the subject pair in step with `task_id`.
     *
     * Without this, every reminder created the old way — and all four products
     * create them the old way — would be invisible to anything that asks by
     * subject, including the shared delivery. The row would look fine and
     * reach nobody, which is the exact failure this extraction was meant to
     * end.
     */
    protected static function booted(): void
    {
        static::creating(function (self $erinnerung) {
            if ($erinnerung->task_id !== null && $erinnerung->subject_id === null) {
                // Die Klasse des PRODUKTS, nicht die des Pakets — jedes
                // Produkt leitet Task ab. `getMorphClass()` liefert dabei den
                // Morph-Alias, falls einer eingetragen ist, und genau der
                // gehoert gespeichert.
                $klasse = (string) config('tasks.task_model', Task::class);

                $erinnerung->subject_type = (new $klasse)->getMorphClass();
                $erinnerung->subject_id = $erinnerung->task_id;
            }
        });
    }
}
