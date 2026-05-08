<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Workflow\Node;

abstract class CountableNode extends Node
{
    protected static int $globalExecutionCount = 0;

    public static function resetExecutionCount(): void
    {
        self::$globalExecutionCount = 0;
    }

    public static function getExecutionCount(): int
    {
        return self::$globalExecutionCount;
    }

    protected function recordExecution(): void
    {
        self::$globalExecutionCount++;
    }
}
