<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use RuntimeException;

class CrashAfterInitialization extends InMemoryPersistence
{
    public bool $crash = true;

    public function initializeIfAbsent(string $partition, string $conditionKey, string $initialValue, array $records = []): bool
    {
        $initialized = parent::initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
        if ($initialized && $this->crash) {
            $this->crash = false;
            throw new RuntimeException('Lost initialization acknowledgement');
        }
        return $initialized;
    }
}
