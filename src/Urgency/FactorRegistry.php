<?php

namespace Peppermint\Tasks\Urgency;

/** The urgency factors of THIS application, in registration order. */
class FactorRegistry
{
    /** @var array<string, UrgencyFactor> */
    protected array $factors = [];

    public function register(UrgencyFactor $factor): void
    {
        $this->factors[$factor->key()] = $factor;
    }

    /** @return array<string, UrgencyFactor> */
    public function all(): array
    {
        return $this->factors;
    }

    public function has(string $key): bool
    {
        return isset($this->factors[$key]);
    }

    /**
     * Drop a factor the application does not want.
     *
     * Needed because the package registers four by default. An application
     * that weighs the age of a task differently — or not at all — should be
     * able to say so without rebuilding the list.
     */
    public function forget(string $key): void
    {
        unset($this->factors[$key]);
    }
}
