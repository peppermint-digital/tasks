<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Priority\TaskPriority;
use Peppermint\Tasks\Status\TaskStatus;
use Peppermint\Tasks\Status\VtodoStatus;

/**
 * Ein erfundenes Vokabular fuer die Tests.
 *
 * Bewusst NICHT das des Managers oder des Brains: Der Test soll pruefen, dass
 * das Paket ein beliebiges Vokabular traegt — nicht, dass es zufaellig unseres
 * kennt.
 */
class Geparkt extends TaskStatus
{
    public function key(): string
    {
        return 'geparkt';
    }

    public function label(): string
    {
        return 'Geparkt';
    }

    public function vtodo(): VtodoStatus
    {
        return VtodoStatus::NeedsAction;
    }

    public function urgencyCoefficient(): float
    {
        return -3.0;
    }
}
