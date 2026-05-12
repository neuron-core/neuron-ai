<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

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
