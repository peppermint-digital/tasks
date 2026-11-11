<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\TaskSource;

/**
 * Eine Fremdquelle, die auch nach Datensaetzen gefragt werden kann.
 *
 * Getrennt von {@see ErfundeneQuelle}, und genau darum geht es: Die alte
 * Attrappe bleibt unveraendert und muss `tasksForSubject` nicht kennen. Waere
 * die Methode abstrakt, liesse sich dieser Test gar nicht schreiben, ohne jede
 * bestehende Quelle anzufassen — in den Produkten waere es dasselbe, nur mit
 * einem Totalausfall.
 */
class ErfundeneSubjektquelle extends TaskSource
{
    /**
     * @param  array<string, array<int, ForeignTask>>  $nachSubjekt  "art:id" => Aufgaben
     */
    public function __construct(
        private string $key = 'fremd-subjekt',
        private array $nachSubjekt = [],
        private ?ForeignSourceFailed $faellt = null,
        private bool $verfuegbar = true,
        private bool $beantwortetPersonen = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fremdes System mit Datensaetzen';
    }

    public function isAvailable(): bool
    {
        return $this->verfuegbar;
    }

    public function tasks(int $userId): array
    {
        return [];
    }

    public function supportsSubjects(): bool
    {
        return true;
    }

    /**
     * Vorgabe `true`, weil eine Quelle beides koennen DARF — und weil die
     * bestehenden Tests von einer Attrappe ausgehen, die auch als
     * Personen-Quelle gilt.
     *
     * Abwaehlbar, weil die echte Brueckenquelle `false` sagt: Sie beantwortet
     * nur die Datensatz-Frage. Genau dieser Fall muss pruefbar sein, denn an
     * ihm haengt, dass `collect()` ueber `forPersons()` laeuft und nicht ueber
     * `available()` — sonst geht ein Aufruf ueber die Systemgrenze fuer eine
     * Frage hinaus, die die Quelle gar nicht haelt.
     */
    public function supportsPerson(): bool
    {
        return $this->beantwortetPersonen;
    }

    public function tasksForSubject(string $type, string|int $id): array
    {
        if ($this->faellt !== null) {
            throw $this->faellt;
        }

        return $this->nachSubjekt["{$type}:{$id}"] ?? [];
    }
}
