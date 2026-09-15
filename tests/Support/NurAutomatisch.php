<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Kinds\TaskKind;

/**
 * Eine Art, die nur durch einen anderen Vorgang entsteht — ein Ticket aus dem
 * Kundenportal etwa. In einer Maske „was moechten Sie anlegen?" waere sie eine
 * Frage ohne brauchbare Antwort.
 */
class NurAutomatisch extends TaskKind
{
    public function key(): string
    {
        return 'ticket';
    }

    public function label(): string
    {
        return 'Ticket';
    }

    public function isUserCreatable(): bool
    {
        return false;
    }
}
