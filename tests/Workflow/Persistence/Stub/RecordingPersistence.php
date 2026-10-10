<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use NeuronAI\Workflow\Persistence\InMemoryPersistence;

use function array_keys;
use function array_map;

/** In-memory backend that records the keys of every write the engine asks it for. */
class RecordingPersistence extends InMemoryPersistence
{
    /** @var list<list<string>> The keys of each write, in order; a delete carries none. */
    public array $writes = [];

    public function initializeIfAbsent(
        string $partition,
        string $conditionKey,
        string $initialValue,
        array $records = [],
    ): bool {
        $this->writes[] = [$conditionKey, ...$this->keys($records)];

        return parent::initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
    }

    public function writeIfUnchanged(
        string $partition,
        string $conditionKey,
        string $expectedValue,
        array $records,
    ): bool {
        $this->writes[] = $this->keys($records);

        return parent::writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
    }

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        $this->writes[] = [];

        return parent::deleteIfUnchanged($partition, $conditionKey, $expectedValue);
    }

    /**
     * @param array<array-key, string> $records
     * @return list<string>
     */
    protected function keys(array $records): array
    {
        return array_map(strval(...), array_keys($records));
    }
}
