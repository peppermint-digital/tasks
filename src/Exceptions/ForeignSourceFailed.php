<?php

namespace Peppermint\Tasks\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An operation in another system failed.
 *
 * ## Why this has to fail LOUDLY
 *
 * When READING, the registry swallows an outage on purpose: a task list left
 * empty because somebody else's server is slow would be worse than one that is
 * incomplete — you can see incompleteness, nobody can explain a blank page.
 *
 * When WRITING the opposite holds. Minutes pass between "page opened" and
 * "save pressed"; a source that answered on opening can be dead by the time
 * the task is created. Swallowing that too leaves behind a task somebody
 * BELIEVES they created and that exists nowhere. An error message beats a task
 * that does not exist.
 *
 * The same applies to ticking one off: the list may only keep the new state
 * once the other system has confirmed it. A checkbox that springs back is
 * annoying; one that stays ticked while the task is still open over there is a
 * lie the person acts on.
 */
final class ForeignSourceFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $sourceKey,
        public readonly string $operation,
        ?Throwable $cause = null,
    ) {
        parent::__construct($message, 0, $cause);
    }

    public static function during(string $operation, string $sourceKey, ?Throwable $cause = null): self
    {
        $detail = $cause !== null ? ' — '.$cause->getMessage() : '';

        return new self(
            "The operation \"{$operation}\" failed in the system \"{$sourceKey}\".{$detail}",
            $sourceKey,
            $operation,
            $cause,
        );
    }
}
