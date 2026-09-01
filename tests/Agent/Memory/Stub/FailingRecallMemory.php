<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use RuntimeException;

class FailingRecallMemory extends InspectableMemory
{
    public function recall(string $query): array
    {
        throw new RuntimeException('Memory unavailable.');
    }
}
