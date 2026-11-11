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

    public function tasksForSubject(string $type, string|int $id): array
    {
        if ($this->faellt !== null) {
            throw $this->faellt;
        }

        return $this->nachSubjekt["{$type}:{$id}"] ?? [];
    }
}
