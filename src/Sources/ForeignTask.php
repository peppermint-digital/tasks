<?php

namespace Peppermint\Tasks\Sources;

/**
 * A task that lives in another system, as this one gets to see it.
 *
 * Deliberately a plain value object and not a model: it is NOT saved here.
 * A copy would be a second truth with a reconciliation problem, and the moment
 * the two drift, nobody can say which one is right. The task stays where it
 * belongs; this is a view of it.
 *
 * Therefore also read-only. Changing a foreign task means asking its system to
 * change it — a different operation with different permissions, not a write to
 * a local row.
 */
class ForeignTask
{
    /**
     * @param  string  $sourceKey  which source this came from
     * @param  string|int  $id  the identifier IN THE OTHER SYSTEM — never a local one
     * @param  string|null  $status  the other system's word, not translated
     * @param  float|null  $urgency  the number, not the breakdown: its factors
     *                               refer to things this system does not have
     * @param  array<string, mixed>  $extra  anything the other system wants shown
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string|int $id,
        public readonly string $title,
        public readonly ?string $status = null,
        public readonly ?string $priority = null,
        public readonly ?string $dueDate = null,
        public readonly ?float $urgency = null,
        public readonly ?string $url = null,
        public readonly array $extra = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceKey,
            // Prefixed so it can never be mistaken for a local id — the kind
            // of mix-up that opens the wrong task, or worse, edits it.
            'id' => $this->sourceKey.':'.$this->id,
            'foreign_id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->dueDate,
            'urgency' => $this->urgency,
            'url' => $this->url,
            'extra' => $this->extra,
            'external' => true,
        ];
    }
}
