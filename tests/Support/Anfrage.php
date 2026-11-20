<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Kinds\TaskKind;

/** Eine erfundene Art mit Bereich — so meldet der Monolith die Arten eines Moduls an. */
class Anfrage extends TaskKind
{
    public function key(): string
    {
        return 'anfrage';
    }

    public function label(): string
    {
        return 'Anfrage';
    }

    public function area(): ?string
    {
        return 'crm';
    }
}
