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
class Dringend extends TaskPriority
{
    public function key(): string
    {
        return 'dringend';
    }

    public function label(): string
    {
        return 'Dringend';
    }

    public function urgencyCoefficient(): float
    {
        return 8.0;
    }

    public function vtodo(): int
    {
        return 1;
    }
}
