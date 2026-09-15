<?php

namespace Peppermint\Tasks\Exceptions;

use LogicException;

/**
 * A kind was given a field it states it must never carry.
 *
 * Deliberately an exception and not a silent unset: the caller believed the
 * value would have an effect. Dropping it quietly is how a routine ends up
 * with a due date that nobody applies and everybody assumes is working.
 */
class ForbiddenAttributeForKind extends LogicException
{
    public static function make(string $kind, string $attribute): self
    {
        return new self(sprintf(
            'A task of kind [%s] must not carry [%s].',
            $kind,
            $attribute,
        ));
    }
}
