<?php

namespace Peppermint\Tasks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder a person set for themselves on a task.
 *
 * ## Why this is not the due date
 *
 * A due date says when the work must be finished. It belongs to the task and
 * there is exactly one. A reminder says when someone wants to be spoken to
 * about it — there can be several, they can be worded, and they are dismissed
 * rather than completed.
 *
 * Conflating the two costs both: a due date moved to get an earlier nudge is a
 * lie about the deadline, and a single reminder cannot cover "look at this on
 * Friday, and again before the meeting".
 *
 * ## Why dismissed rows stay
 *
 * `is_dismissed` marks, it does not delete. The row is the record that someone
 * was reminded and acted — the history. Deleting it would answer "is anything
 * pending" faster and make "what did we already chase" unanswerable.
 *
 * ## Where it came from
 *
 * Built in the Peppermint Manager (12/2025) and never used there: table, UI
 * path and MCP tool existed, zero rows. When AI Brain needed the same thing on
 * 17.09.2026, the choice was a second implementation or one shared. Two
 * implementations of the same idea drift; this is the third product that would
 * have written it.
 */
class TaskReminder extends Model
{
    protected $guarded = [];

    /**
     * The table, resolvable — same reason as {@see Task::getTable()}: a grown
     * product keeps its own name instead of being renamed into the package.
     */
    public function getTable(): string
    {
        return config('tasks.tables.task_reminders', 'task_reminders');
    }

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'is_dismissed' => 'boolean',
            'dismissed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('tasks.user_model'), 'user_id');
    }

    /** Not dismissed — still wants something from someone. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_dismissed', false);
    }

    /** Its moment has come or passed. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('remind_at', '<=', now());
    }

    /** Still ahead. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('remind_at', '>', now());
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Mark as dealt with.
     *
     * Idempotent on purpose: a second dismissal must not move the timestamp,
     * or the history says the person acted later than they did.
     */
    public function dismiss(): bool
    {
        if ($this->is_dismissed) {
            return false;
        }

        return $this->update(['is_dismissed' => true, 'dismissed_at' => now()]);
    }
}
