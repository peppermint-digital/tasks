<?php

namespace Peppermint\Tasks\Sources;

use Closure;

/**
 * A foreign task system, described by its particulars and one way to call it.
 *
 * ## Why this class exists
 *
 * {@see RemoteTaskSystem} is a contract, and a contract wants fulfilling. At
 * the FIRST consumer that fulfilment looked product-specific: a class holding
 * slug, name and capabilities, sending calls through the application's
 * communication layer.
 *
 * At the SECOND it was character-for-character the same, bar the user model and
 * the auth guard. That is exactly the point at which the package contract
 * demands the question "is every product writing the same wiring code?", and
 * the answer was yes. So it moves here, before the second copy exists and
 * starts drifting with the first fix.
 *
 * ## Why a closure and not a dependency
 *
 * The way of calling is what genuinely differs between applications: a hub
 * calls its connected products through its own gateway, a product through its
 * SDK, a third through something else. Were this package to presuppose one of
 * them, it would force a dependency on every consumer that two out of three do
 * not need.
 *
 * A closure is not a trick for that but the exact description: "give me an
 * answer to an operation". The application writes one line for it instead of
 * sixty.
 *
 * ```php
 * new RemoteTaskSource(new CallableRemoteTaskSystem(
 *     key: 'verwaltung',
 *     label: 'Verwaltung',
 *     capabilities: ['tasks.list', 'tasks.kinds.list'],
 *     transport: fn (string $operation, array $data) => $client->call($operation, $data, 'peppermint-verwaltung'),
 *     identify: fn (int $userId) => User::find($userId)?->email,
 * ));
 * ```
 *
 * Whoever needs more — a different `label()`, capabilities asked live instead
 * of listed — fulfils the contract themselves. This class is the shortcut for
 * the ordinary case, not the only door.
 */
class CallableRemoteTaskSystem implements RemoteTaskSystem
{
    /**
     * @param  string  $key  Stable, lower case, without the vendor prefix — it sits in the task
     *                       identifiers ("manager:7"), in the kind keys and in users' saved filters.
     * @param  array<int, string>  $capabilities  What the system can do AND may be triggered here.
     * @param  Closure(string, array<string, mixed>): RemoteResult  $transport
     * @param  Closure(int): ?string  $identify  The person's identifier in the foreign system.
     *                                           `null` means: the operation does not take place, and
     *                                           the list stays empty rather than widening.
     */
    public function __construct(
        protected readonly string $key,
        protected readonly string $label,
        protected readonly array $capabilities,
        protected readonly Closure $transport,
        protected readonly Closure $identify,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function offers(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function identify(int $userId): ?string
    {
        $identifier = ($this->identify)($userId);

        // An empty string is not an identity. A transport that sends one along
        // would ask in nobody's name, and the target system would answer with
        // whatever it considers its default — which is the wider answer, and
        // the one thing the identity bolt exists to prevent.
        return is_string($identifier) && $identifier !== '' ? $identifier : null;
    }

    public function call(string $capability, array $arguments = []): RemoteResult
    {
        $answer = ($this->transport)($capability, $arguments);

        // A transport that delivers something else is a mistake in the caller —
        // but it must not take the task list with it. So the same path as an
        // unreachable system: a failure with a reason, not a crash in the
        // middle of drawing.
        return $answer instanceof RemoteResult
            ? $answer
            : RemoteResult::failed(
                'Der Aufrufweg zu '.$this->label.' hat kein '.RemoteResult::class.' geliefert.',
                'tool_failed',
            );
    }
}
