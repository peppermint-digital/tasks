<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Sources\PlannedBlock;
use Peppermint\Tasks\Sources\PlansForeignTasks;
use Peppermint\Tasks\Sources\TaskSource;
use Peppermint\Tasks\Sources\TimeSpan;

/**
 * Eine erfundene Fremdquelle, in deren System man Zeit einplanen darf.
 *
 * Getrennt von {@see ErfundeneSchreibquelle}, und das ist der Gegenstand: Der
 * Planungsvertrag ist ein ZUSAETZLICHER, per `instanceof` gefragt. Waere er an
 * {@see \Peppermint\Tasks\Sources\WritableTaskSource} gehaengt, liesse sich
 * dieser Fall gar nicht aufschreiben — eine Quelle, die schreiben kann und
 * nicht planen, und eine, die planen kann ohne schreibbar zu sein. Beide gibt
 * es: Die Verwaltung nimmt keine neue Aufgabe von draussen an, einen Zeitblock
 * an einem Ticket aber schon.
 *
 * Merkt sich, WAS angekommen ist, nicht bloss DASS etwas ankam. Ein
 * Planungsweg, der den Zeitraum entgegennimmt und einen anderen weiterschickt,
 * sieht von aussen genauso aus wie ein richtiger.
 */
class ErfundenePlanquelle extends TaskSource implements PlansForeignTasks
{
    /** @var array<int, array{task: string, span: TimeSpan}> */
    public array $geplant = [];

    /** @var array<int, array{block: string, span: TimeSpan}> */
    public array $verschoben = [];

    /** @var array<int, string> */
    public array $ausgetragen = [];

    /**
     * @param  array<int, string>  $vorgaenge  Welche der drei das erfundene
     *                                         System anbietet. Vorgabe: alle
     *                                         drei.
     */
    public function __construct(
        private string $key = 'planbar',
        private ?array $vorgaenge = null,
        private bool $verfuegbar = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fremdes System mit Planung';
    }

    public function isAvailable(): bool
    {
        return $this->verfuegbar;
    }

    public function tasks(int $userId): array
    {
        return [];
    }

    public function offers(string $capability): bool
    {
        return in_array($capability, $this->vorgaenge ?? self::CAPABILITIES, true);
    }

    public function plan(int $userId, string|int $taskId, TimeSpan $span): PlannedBlock
    {
        $this->geplant[] = ['task' => (string) $taskId, 'span' => $span];

        // Bewusst ANDERE Zeiten als die geschickten: Was zurueckkommt, ist die
        // Fassung des anderen Systems. Ein Test, der die eigenen Zeiten
        // zurueckerwartet, wuerde nie merken, wenn jemand die Antwort verwirft
        // und die Anfrage weiterreicht.
        return new PlannedBlock(
            sourceKey: $this->key,
            id: 7,
            startsAt: '2026-11-17T09:15:00+01:00',
            endsAt: '2026-11-17T11:15:00+01:00',
            title: 'Aufgabe '.$taskId.' (drueben)',
            kind: 'fremd_task',
        );
    }

    public function move(int $userId, string|int $blockId, TimeSpan $span): PlannedBlock
    {
        $this->verschoben[] = ['block' => (string) $blockId, 'span' => $span];

        return new PlannedBlock(
            sourceKey: $this->key,
            id: $blockId,
            startsAt: $span->startsAt,
            endsAt: $span->endsAt,
            title: 'Verschoben',
            kind: 'fremd_task',
        );
    }

    public function unplan(int $userId, string|int $blockId): void
    {
        $this->ausgetragen[] = (string) $blockId;
    }
}
