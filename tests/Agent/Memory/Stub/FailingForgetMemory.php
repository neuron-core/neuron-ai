<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use RuntimeException;

class FailingForgetMemory extends InspectableMemory
{
    public function forget(string $threadId): void
    {
        throw new RuntimeException('Memory store unavailable.');
    }
}
