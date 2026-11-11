<?php

namespace Peppermint\Tasks\Sources;

/**
 * One source could not answer — with the reason kept apart from the message.
 *
 * ## Why the class matters and not only the text
 *
 * "Not offered", "not allowed", "unreachable" and "the tool itself failed" are
 * four different situations with four different next steps: wait, ask for a
 * permission, check the other system, read its log. A single sentence collapses
 * them, and then the interface says "something went wrong" — which tells the
 * person nothing they can act on.
 *
 * The four names come from the comm layer, which is what sources travel over.
 * They are kept as plain strings on purpose: this package must not depend on
 * the bridge to name them (product contract, rule 2), and a product that uses
 * a different transport can still fill them in.
 *
 * ## Why this is not called SubjectSourceFailure any more
 *
 * It was, until 11.11.2026, and it was only reachable from the subject query.
 * The same four reasons hold for the PERSON query — a CRM that is not reachable
 * is not reachable for either question — and the merge of the two harvest
 * shapes is what made that visible. A name that says "subject" on a class the
 * person query also fills would send the next reader looking for a second one.
 *
 * ## And why `reason` holds the REASON here
 *
 * In the first version the free text sat in `reason` and the typed class in
 * `errorClass`, while the class head said the opposite. Nothing broke from it —
 * `mightPassByItself()` read the right field — but whoever wrote
 * `new SubjectSourceFailure($key, SubjectSourceFailure::NOT_ALLOWED)` got a
 * failure with a reason of null and a message of "not_allowed", and no test
 * anywhere would have said so. The names now match what they carry: `reason`
 * is one of the four, `message` is the sentence a person reads.
 */
final class SourceFailure
{
    /** The other system does not offer this operation at all. */
    public const NOT_OFFERED = 'not_offered';

    /** It offers it, but not to us. */
    public const NOT_ALLOWED = 'not_allowed';

    /** Nobody answered. */
    public const UNREACHABLE = 'unreachable';

    /** It answered, and the answer was an error. */
    public const TOOL_FAILED = 'tool_failed';

    /**
     * The whole set — so a caller can check against it instead of repeating
     * four strings, and so a test can prove the wire contract is closed.
     */
    public const REASONS = [
        self::NOT_OFFERED,
        self::NOT_ALLOWED,
        self::UNREACHABLE,
        self::TOOL_FAILED,
    ];

    /**
     * One of {@see REASONS}, or null when nobody named one.
     *
     * Null is a real state and not a gap to be filled with a guess: a source
     * that cannot tell the four apart must still be able to report a failure
     * (see {@see \Peppermint\Tasks\Exceptions\ForeignSourceFailed}). An
     * interface shows "could not be asked" for it — which is all anybody knows.
     *
     * And null rather than a fifth name like "unknown", which was considered
     * and rejected on 11.11.2026: "unknown" looks like a reason and is none. It
     * would land in whatever table maps reasons to sentences, get a sentence
     * there, and the sentence would say nothing — while null forces the
     * interface to fall back on `message`, which actually says something. A
     * placeholder disguised as information is worse than missing information.
     */
    public readonly ?string $reason;

    /**
     * @param  string  $sourceKey  the source that stayed silent
     * @param  string  $message  human-readable, the other end's own words where
     *                           it had any: "Verwaltung not reachable: timeout"
     *                           tells somebody what to do, "error" does not
     * @param  string|null  $reason  one of {@see REASONS}
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly string $message,
        ?string $reason = null,
    ) {
        // Anything the other end invented is NOT passed on as a reason. The
        // four names are a closed set on the wire, and a consumer that switches
        // over them would otherwise meet a fifth value it has no branch for —
        // which is the one failure mode typing them was meant to end. The text
        // still carries whatever was said.
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : null;
    }

    /**
     * Can waiting help?
     *
     * The split an interface actually needs. "Unreachable" and "the tool
     * failed" may pass; "not offered" and "not allowed" will not change until
     * somebody changes something — offering a reload there is a false promise.
     *
     * An unnamed reason is NOT retryable here. Promising a retry for something
     * nobody could name is the more expensive mistake: it costs a person a
     * second wait and tells them nothing new.
     */
    public function mightPassByItself(): bool
    {
        return in_array($this->reason, [self::UNREACHABLE, self::TOOL_FAILED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceKey,
            'reason' => $this->reason,
            'message' => $this->message,
            // Travels along rather than being left to the reader: the rule
            // "waiting can help" belongs to the four reasons, and a mask that
            // rebuilds it from the reason has to be corrected a second time
            // when a fifth reason appears.
            'may_retry' => $this->mightPassByItself(),
        ];
    }
}
