<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Sources\ForeignKind;
use Peppermint\Tasks\Sources\ForeignTask;
use Peppermint\Tasks\Sources\NewForeignTask;
use Peppermint\Tasks\Sources\WritableTaskSource;

/**
 * Eine erfundene Fremdquelle, in die man auch schreiben darf.
 *
 * Merkt sich, WAS bei ihr angekommen ist — nicht bloss, dass sie aufgerufen
 * wurde. Der Unterschied zaehlt: Ein Schreibweg, der die Aenderung
 * entgegennimmt und etwas anderes weiterschickt, sieht von aussen genauso aus
 * wie ein richtiger.
 */
class ErfundeneSchreibquelle extends WritableTaskSource
{
    /** @var array<int, NewForeignTask> */
    public array $angelegt = [];

    /** @var array<int, array{id: string, status: string}> */
    public array $statuswechsel = [];

    /** @var array<int, string> */
    public array $geloescht = [];

    /** @param  array<int, ForeignKind>  $kinds */
    public function __construct(
        private string $key = 'fremd',
        private array $kinds = [],
        private bool $schreibbar = true,
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
        return [];
    }

    public function kinds(): array
    {
        return $this->kinds;
    }

    public function isWritable(): bool
    {
        return $this->schreibbar;
    }

    public function create(int $userId, NewForeignTask $task): ForeignTask
    {
        if ($this->faellt) {
            throw ForeignSourceFailed::during('create', $this->key);
        }

        $this->angelegt[] = $task;

        // Bewusst ein ANDERER Titel als der geschickte: Was zurueckkommt, ist
        // die Fassung des anderen Systems. Ein Test, der den eigenen Titel
        // zurueckerwartet, wuerde nie merken, wenn jemand die Antwort verwirft
        // und die Anfrage weiterreicht.
        return new ForeignTask(
            sourceKey: $this->key,
            id: 7,
            title: $task->title.' (drueben)',
            status: 'offen',
            kind: $task->kind,
        );
    }

    public function changeStatus(int $userId, string $taskId, string $status): ForeignTask
    {
        if ($this->faellt) {
            throw ForeignSourceFailed::during('changeStatus', $this->key);
        }

        $this->statuswechsel[] = ['id' => $taskId, 'status' => $status];

        return new ForeignTask(
            sourceKey: $this->key,
            id: $taskId,
            title: 'Fremd',
            status: $status,
        );
    }

    public function delete(int $userId, string $taskId): void
    {
        if ($this->faellt) {
            throw ForeignSourceFailed::during('delete', $this->key);
        }

        $this->geloescht[] = $taskId;
    }
}
