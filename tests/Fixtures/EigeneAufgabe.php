<?php

namespace Peppermint\Tasks\Tests\Fixtures;

use Peppermint\Tasks\Models\Task;

/**
 * Das Aufgabenmodell einer Anwendung — so, wie jedes Produkt es fuehrt.
 *
 * `App\Models\Task` im Manager, im CRM und in der Verwaltung erbt genauso vom
 * Paketmodell und traegt die produkteigenen Felder. Dieser Doppelgaenger ist
 * da, damit `TaskDeadlineSource` nachweislich DAS Modell fragt, das die
 * Anwendung ihm nennt — und nicht sein eigenes.
 */
class EigeneAufgabe extends Task
{
}
