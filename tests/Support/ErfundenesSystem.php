<?php

namespace Peppermint\Tasks\Tests\Support;

use Peppermint\Tasks\Sources\RemoteResult;
use Peppermint\Tasks\Sources\RemoteTaskSystem;

/**
 * Ein erfundenes fremdes Aufgaben-System fuer die Tests.
 *
 * Merkt sich, WAS bei ihm angekommen ist — nicht bloss, dass es gefragt wurde.
 * Der Unterschied zaehlt: Eine Quelle, die den Vorgang ausloest und andere
 * Argumente weiterschickt, sieht von aussen genauso aus wie eine richtige.
 */
class ErfundenesSystem implements RemoteTaskSystem
{
    /** @var array<int, array{vorgang: string, argumente: array<string, mixed>}> */
    public array $aufrufe = [];

    /**
     * @param  array<int, string>  $faehigkeiten
     * @param  array<string, RemoteResult>  $antworten  Vorgang => Antwort.
     */
    public function __construct(
        private string $key = 'fremd',
        private array $faehigkeiten = ['tasks.list'],
        private array $antworten = [],
        private ?string $kennung = 'jemand@example.com',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fremdes System';
    }

    public function offers(string $capability): bool
    {
        return in_array($capability, $this->faehigkeiten, true);
    }

    public function identify(int $userId): ?string
    {
        return $this->kennung;
    }

    public function call(string $capability, array $arguments = []): RemoteResult
    {
        $this->aufrufe[] = ['vorgang' => $capability, 'argumente' => $arguments];

        // Vorgabe: ein Erfolg ohne Zeilen. Wer einen Ausfall braucht, legt ihn
        // als Antwort hin — ein Vertrag, der nicht wirft, soll auch im Test
        // nicht werfen.
        return $this->antworten[$capability] ?? RemoteResult::ok([]);
    }
}
