<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Kinds\TaskKind;

/** Die gewoehnliche Art: verbietet nichts, kennt alle Status. */
class Vorgang extends TaskKind
{
    public function key(): string
    {
        return 'vorgang';
    }

    public function label(): string
    {
        return 'Vorgang';
    }
}
