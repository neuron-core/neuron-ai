<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes\Fixtures;

/**
 * Test callable that can be serialized.
 */
class TestCallable
{
    public function execute(): string
    {
        return 'result';
    }
}
