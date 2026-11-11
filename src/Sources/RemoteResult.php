<?php

namespace Peppermint\Tasks\Sources;

/**
 * Another system's answer to an operation.
 *
 * Deliberately poor: four fields, no behaviour. It is the seam between this
 * package and whatever path the application uses to talk to other systems —
 * and a seam that can do things becomes the second place where things get
 * decided.
 *
 * `ok` is not derivable from `data`. An operation can succeed and return
 * nothing (deleting), and it can fail and still send something along (an error
 * description). Whoever checks `data !== null` mistakes one for the other.
 *
 * ## Why this is a SECOND copy of four fields
 *
 * `peppermint/calendar` has a class of the same name and the same four fields,
 * and this is not an oversight to be cleaned up later. Reusing the calendar's
 * would make `peppermint/calendar` a hard requirement of `peppermint/tasks` —
 * and the dependency between these two packages runs from the task package to
 * the calendar as an OPTIONAL suggestion (`TaskBlockKind`), never as a
 * requirement. A task list that cannot be installed without a calendar is the
 * worse outcome by a distance; four fields and two constructors are the
 * cheaper price.
 *
 * The other direction is worse still: the calendar must not require the task
 * package, or the two can only ever ship together. Whoever is tempted to
 * extract these fields into a third package should first count the consumers —
 * one package of four fields, required by both, is a release step nobody
 * forgets only until the first time they do.
 */
class RemoteResult
{
    /**
     * @param  array<mixed>|null  $data  What the target system delivered — its version, not the one sent.
     * @param  string|null  $message  Human-readable. This is the text somebody ends up reading.
     * @param  string|null  $error  The class of failure, as far as the other end names one:
     *                              `not_offered`, `not_allowed`, `unreachable`, `tool_failed`.
     *                              There so a caller can tell whether a second attempt makes
     *                              sense — without guessing at strings.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?array $data = null,
        public readonly ?string $message = null,
        public readonly ?string $error = null,
    ) {}

    /** @param  array<mixed>|null  $data */
    public static function ok(?array $data = null): self
    {
        return new self(true, $data);
    }

    public static function failed(string $message, ?string $error = null): self
    {
        return new self(false, null, $message, $error);
    }

    /**
     * From a communication layer's answer.
     *
     * The four keys `ok`, `data`, `message`, `error` are the shape every path
     * known to us answers in. Having the conversion here rather than in every
     * product is not a luxury: it is exactly the place where a misplaced `??`
     * turns a failure into a silent success.
     *
     * `message` falls back to `error`: an other end that names only the class
     * of failure must not produce an error message without text — "not
     * reachable: " is worse than "not reachable: unreachable".
     *
     * ## `ok` is read STRICTLY, and that is not a style question
     *
     * Only a real `true` counts. This was `(bool) ($answer['ok'] ?? false)`
     * until 11.11.2026, and the case that reading gets wrong is this one:
     *
     *     ['ok' => 'false', 'data' => null]   // (bool) 'false' === true
     *
     * A system answering that way is read as a SUCCESS. `data` is then no
     * list, `rows()` is empty, and what comes out is an empty task list that
     * presents itself as a complete answer — "no open tasks" for a system
     * that has some, with nothing on the page to notice. That is the exact
     * failure this whole package is built against, produced by the one line
     * that was supposed to prevent it.
     *
     * The same holds for `1`, `'1'`, `'yes'` and every other truthy thing that
     * is not a bool: all of them are a MALFORMED answer, and a malformed
     * answer is not a success. Failing closed costs an error message where a
     * system misbehaves; failing open costs a wrong answer nobody sees.
     *
     * Whoever wants to loosen this again has to want that empty list.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function fromArray(array $answer): self
    {
        return new self(
            ok: ($answer['ok'] ?? null) === true,
            data: is_array($answer['data'] ?? null) ? $answer['data'] : null,
            message: $answer['message'] ?? $answer['error'] ?? null,
            error: $answer['error'] ?? null,
        );
    }

    /**
     * The rows of the answer — each on its own, and only the ones that are one.
     *
     * A list with a string lying between the rows is not automatically
     * useless: the rest stays readable, and another system should not be able
     * to empty the task list by sending something unexpected once.
     *
     * ## The envelope is unwrapped, not insisted upon
     *
     * The contract is a BARE list. A product that answers
     * `{count: 3, data: [...]}` instead used to produce three tasks without a
     * title here — one per key of the envelope. No error, no message, just
     * nonsense in the list, and nobody searching at "(untitled)" suspects a
     * wrong answer shape.
     *
     * So it is unpacked rather than rejected: the strict reading punishes the
     * reader for the writer's mistake. This is the one place where this class
     * knowingly differs from the calendar's namesake — the calendar never met
     * the envelope, the task tools did.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        $data = (array) ($this->data ?? []);

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return array_values(array_filter($data, 'is_array'));
    }
}
