<?php

namespace Peppermint\Tasks\Sources;

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;

/**
 * The block another system made of a planning request, as this one gets to see
 * it.
 *
 * A plain value object and not a model, for the same reason as
 * {@see ForeignTask}: it is NOT saved here. The block lives in the database of
 * the system that owns the task — it has to, because a
 * `PlannedBlockProfile` points at a LOCAL Eloquent model
 * (`calendar/src/Models/PlannedBlockProfile.php:41-44`) and a foreign task has
 * neither model nor row here.
 *
 * And that placement is what makes the hardest question disappear: when the
 * task is deleted over there, the block goes or stays by the rule of the
 * owning system's own kind, in its own database. Were the block local, a
 * deletion over there could not reach it, and this system would show time as
 * planned that is free. The contract keeps the shape by NOT touching it.
 *
 * ## It is THEIR version, not ours
 *
 * The same rule as `WritableTaskSource::create()` — "returns what the other
 * system made of it, not what we sent". The other system applies its own kind,
 * derives the title from its own task, and attaches its own identifier. A
 * value object built from what we SENT would be green in every test and wrong
 * on every screen.
 *
 * ## Why these fields and not others
 *
 * This reads the shape the three products already give an event out in
 * (`peppermint-crm/app/Mcp/Concerns/ReichtTermineHeraus.php:45-66`). Not a new
 * shape beside it: a second spelling of the same row is the point at which two
 * systems start disagreeing about what an event is, and the one that is read
 * less often is the one that drifts.
 *
 * Everything the shape carries beyond the fixed fields travels in `extra`,
 * unfiltered — exactly as in {@see BrueckenSubjektquelle::uebersetzen()}: what
 * leaves the neighbour's house is the neighbour's decision, in its own
 * answering tool. A list of permitted fields here would be a second filter at
 * the wrong place, and every new display field would need a package release.
 */
final class PlannedBlock
{
    /**
     * @param  string  $sourceKey  which system the block lives in
     * @param  string|int  $id  the identifier IN THAT SYSTEM — never a local
     *                          `calendar_events.id`
     * @param  string  $startsAt  ISO-8601 with zone, as the other end wrote it
     * @param  string  $kind  the other system's kind key, untranslated —
     *                        `manager_task`, `crm_task`, `verwaltung_ticket`
     * @param  string|null  $url  where a click goes, FROM over there. Nobody
     *                            can guess it: a guessed address leads to a
     *                            404 and looks like a route that works
     * @param  array<string, mixed>  $extra  anything else the other system sends
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string|int $id,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $title = '',
        public readonly ?string $kind = null,
        public readonly ?string $kindLabel = null,
        public readonly ?string $url = null,
        public readonly array $extra = [],
    ) {}

    /**
     * One answered row, read into a block.
     *
     * ## Why this is here and not in each implementation of the contract
     *
     * Because it is the one place where a malformed answer has to be caught,
     * and a second place to read the same row is a second place where a
     * misplaced `??` turns a failure into a silent success — the argument
     * `RemoteResult::fromArray()` already makes for the four envelope keys.
     *
     * ## Why a missing time THROWS instead of defaulting
     *
     * A block without a beginning and an end is not a block. Were the fields
     * nullable, what would come back from a misbehaving system is an object
     * that passes every type check and shows up in the grid as an entry with
     * no hours — and the person reads it as "planned".
     *
     * The failure class is `tool_failed`, which is precisely the case: "It
     * answered, and the answer was an error" ({@see SourceFailure}). No fifth
     * reason is invented for it — the four are a closed set on the wire, and a
     * consumer switching over them must not meet a value it has no branch for.
     *
     * @param  string  $capability  the operation that was asked, for the throw
     * @param  array<string, mixed>  $row  the bare payload of the answer
     *
     * @throws ForeignSourceFailed when the answer is not a block
     */
    public static function fromRow(string $sourceKey, string $capability, array $row): self
    {
        $pflicht = ['id', 'starts_at', 'ends_at'];

        $fehlend = array_values(array_filter(
            $pflicht,
            static fn (string $feld): bool => ! isset($row[$feld]) || ! is_scalar($row[$feld]),
        ));

        if ($fehlend !== []) {
            throw new ForeignSourceFailed(
                'Die Antwort aus "'.$sourceKey.'" ist kein Zeitblock — es fehlt: '
                    .implode(', ', $fehlend).'.',
                $sourceKey,
                $capability,
                null,
                SourceFailure::TOOL_FAILED,
            );
        }

        return new self(
            sourceKey: $sourceKey,
            id: is_int($row['id']) ? $row['id'] : (string) $row['id'],
            startsAt: (string) $row['starts_at'],
            endsAt: (string) $row['ends_at'],
            title: (string) ($row['title'] ?? ''),
            kind: isset($row['kind']) ? (string) $row['kind'] : null,
            kindLabel: isset($row['kind_label']) ? (string) $row['kind_label'] : null,
            url: isset($row['url']) ? (string) $row['url'] : null,
            // Ungefiltert, wie bei {@see ForeignTask}: `all_day`, `location`,
            // `description` und `user_email` der geteilten Terminform reisen
            // hier mit, ohne dass dieses Paket sie aufzaehlen muss. `all_day`
            // geht HINAUS nicht mit (siehe {@see TimeSpan}) und kommt
            // trotzdem zurueck — das ist kein Widerspruch: Was das andere
            // System ueber seinen Termin sagt, sagt es.
            extra: array_diff_key($row, array_flip([
                'id', 'starts_at', 'ends_at', 'title', 'kind', 'kind_label', 'url',
            ])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceKey,
            // Prefixed so it can never be mistaken for a local event id — the
            // same reasoning and the same spelling as `ForeignTask::toArray()`.
            // Here it is sharper still: a local `calendar_events.id` and a
            // foreign block id are both plain integers, and the operation that
            // would take the wrong one is a delete.
            'id' => $this->sourceKey.':'.$this->id,
            'foreign_id' => $this->id,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'title' => $this->title,
            'kind' => $this->kind,
            'kind_label' => $this->kindLabel ?? $this->kind,
            // The kind with its source in front — for filtering. All three
            // systems have exactly one task-block kind, and two of them differ
            // only by prefix (`crm_task`, `manager_task`); a merged grid that
            // keys by `kind` alone would collapse a third one that happens to
            // repeat a spelling.
            'kind_key' => $this->kind === null ? null : $this->sourceKey.':'.$this->kind,
            'url' => $this->url,
            'extra' => $this->extra,
            'external' => true,
        ];
    }
}
