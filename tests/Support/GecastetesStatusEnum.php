<?php

namespace Peppermint\Tasks\Tests\Support;

/** Wie ein gewachsenes Produkt seine Status fuehrt: als Enum, nicht als Text. */
enum GecastetesStatusEnum: string
{
    case Offen = 'offen';
    case InArbeit = 'in_arbeit';
    case Fertig = 'fertig';
}
