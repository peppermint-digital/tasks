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
 */
final class SubjectSourceFailure
{
    /** The other system does not offer this operation at all. */
    public const NOT_OFFERED = 'not_offered';

    /** It offers it, but not to us. */
    public const NOT_ALLOWED = 'not_allowed';

    /** Nobody answered. */
    public const UNREACHABLE = 'unreachable';

    /** It answered, and the answer was an error. */
    public const TOOL_FAILED = 'tool_failed';

    public function __construct(
        public readonly string $sourceKey,
        public readonly string $reason,
        public readonly ?string $errorClass = null,
    ) {}

    /**
     * Can waiting help?
     *
     * The split an interface actually needs. "Unreachable" and "the tool
     * failed" may pass; "not offered" and "not allowed" will not change until
     * somebody changes something — offering a reload there is a false promise.
     */
    public function mightPassByItself(): bool
    {
        return in_array($this->errorClass, [self::UNREACHABLE, self::TOOL_FAILED], true);
    }
}
