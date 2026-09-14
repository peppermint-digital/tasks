<?php

namespace Peppermint\Tasks\Exceptions;

use InvalidArgumentException;

class UnknownPriority extends InvalidArgumentException
{
    /**
     * Says what was asked for AND what is on offer.
     *
     * A bare "unknown status" sends the reader looking for a typo when the
     * real answer is usually that the application forgot to register it in
     * `config/tasks.php`.
     *
     * @param  array<int, string>  $known
     */
    public static function make(string $key, array $known): self
    {
        $liste = $known === [] ? '(none registered)' : implode(', ', $known);

        return new self("Unknown task priority [{$key}]. Registered in config/tasks.php: {$liste}.");
    }
}
