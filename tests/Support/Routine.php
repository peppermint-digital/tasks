<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Kinds\TaskKind;

/**
 * Eine erfundene Art fuer die Tests — die Routine, weil sie beide Riegel
 * braucht: Sie verbietet ein Feld UND beschraenkt das Statusvokabular.
 *
 * Bewusst nicht die des Managers: Geprueft wird, dass das Paket eine beliebige
 * Art traegt, nicht dass es zufaellig unsere kennt.
 */
class Routine extends TaskKind
{
    public function key(): string
    {
        return 'routine';
    }

    public function label(): string
    {
        return 'Routine';
    }

    public function forbiddenAttributes(): array
    {
        return ['due_date'];
    }

    public function statusKeys(): ?array
    {
        return ['offen'];
    }

    public function restrictUrgencyTo(): ?array
    {
        return ['priority'];
    }

    public function rules(): array
    {
        return ['assigned_to' => 'required|integer'];
    }
}
