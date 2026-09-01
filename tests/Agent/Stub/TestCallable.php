<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

/**
 * Test callable that can be serialized.
 */
class TestCallable
{
    public function __invoke(): string
    {
        return 'result';
    }
}
