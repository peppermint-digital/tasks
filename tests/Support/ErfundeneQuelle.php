<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\TaskSource;

/** Eine erfundene Fremdquelle fuer die Tests. */
class ErfundeneQuelle extends TaskSource
{
    /** @param  array<int, ForeignTask>  $tasks */
    public function __construct(
        private string $key = 'fremd',
        private array $tasks = [],
        private bool $faellt = false,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fremdes System';
    }

    public function tasks(int $userId): array
    {
        if ($this->faellt) {
            throw new \RuntimeException('Nicht erreichbar');
        }

        return $this->tasks;
    }
}
