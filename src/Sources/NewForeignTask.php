<?php

namespace Peppermint\Tasks\Sources;

/**
 * A task that is to come into being in ANOTHER system.
 *
 * The same fields as when reading, plus the target system's kind — over there
 * it decides which fields are allowed at all.
 *
 * No model, no row here: the task belongs to the other system from the first
 * moment. A copy would be a second truth, and the question of which one wins
 * would have no good answer — the same reasoning as when reading, and the
 * reason a product must not route its own tasks through a third system just
 * because its connector cannot write.
 *
 * Only `kind` and `title` are required. Everything else is the target
 * system's business: it has defaults, and imposing ours would be a claim about
 * its rules. What it made of the request comes back as a {@see ForeignTask} —
 * its version, not ours.
 */
final class NewForeignTask
{
    /**
     * @param  string|null  $dueDate  ISO 8601 date, or null. A string and not a
     *                                Carbon instance: a due date carries no
     *                                time of day, and parsing one here would
     *                                invent a timezone the other system never
     *                                stated.
     * @param  array<string, mixed>  $extra  Fields only the target system knows
     *                                       (deal, customer, category). It is
     *                                       free to ignore them — this is a
     *                                       request, not a schema.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $priority = null,
        public readonly ?string $dueDate = null,
        public readonly array $extra = [],
    ) {}

    /**
     * No `status`: a task that does not exist yet has no state to set. The
     * target system decides what "new" means in its own vocabulary, and
     * sending "todo" into a system whose word is "offen" would be the kind of
     * translation these packages exist to avoid.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'due_date' => $this->dueDate,
            'extra' => $this->extra,
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
