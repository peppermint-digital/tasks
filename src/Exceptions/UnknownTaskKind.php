<?php

namespace Peppermint\Tasks\Exceptions;

use InvalidArgumentException;

class UnknownTaskKind extends InvalidArgumentException
{
    /** @param  array<int, string>  $known */
    public static function make(string $key, array $known): self
    {
        // The known keys belong in the message. Without them the reader learns
        // that something is missing but not whether they mistyped, forgot to
        // register, or are looking at a product that has no such kind.
        return new self(sprintf(
            'Unknown task kind [%s]. Registered: %s.',
            $key,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}
