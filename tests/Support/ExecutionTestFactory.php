<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowExecution;

/** Construct resource/graph fixtures without persistence or traversal. */
final class ExecutionTestFactory
{
    public static function context(Workflow $definition): ExecutionContext
    {
        return new ExecutionContext($definition->getWorkflowId() ?? 'test', 'test-run', 1, $definition->makeIgnition('test-run', $definition->getStartEvent()));
    }

    public static function runtime(Workflow $definition): WorkflowExecution
    {
        $definition->setWorkflowId($definition->getWorkflowId() ?? 'test');
        return $definition->createExecution(self::context($definition));
    }
}
