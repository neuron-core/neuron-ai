<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use RuntimeException;

use function array_keys;
use function str_ends_with;

/**
 * What a killed process leaves in the store: the write of the record ending in
 * $killedAt never lands, and neither does any write after it, the failure of
 * the run included.
 */
class KilledProcessPersistence extends InMemoryPersistence
{
    protected bool $killed = false;

    public function __construct(protected ?string $killedAt = null)
    {
    }

    public function killAt(string $record): void
    {
        $this->killedAt = $record;
    }

    /** The next process: its writes land. */
    public function restart(): void
    {
        $this->killed = false;
        $this->killedAt = null;
    }

    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        foreach (array_keys($records) as $key) {
            $this->killed = $this->killed || ($this->killedAt !== null && str_ends_with((string) $key, $this->killedAt));
        }

        if ($this->killed) {
            throw new RuntimeException('The process was killed.');
        }

        return parent::writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
    }
}
