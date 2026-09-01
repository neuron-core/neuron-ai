<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use RuntimeException;

class FailingRememberMemory extends InspectableMemory
{
    public function remember(string $threadId, string $user, string $assistant): void
    {
        throw new RuntimeException('Memory store unavailable.');
    }
}
