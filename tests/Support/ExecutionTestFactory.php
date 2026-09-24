<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
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

    /** The resources an agent builds for a segment. */
    public static function agentResources(Agent $agent): AgentResources
    {
        $resources = self::runtime($agent)->getResources();

        return $resources instanceof AgentResources ? $resources : throw new LogicException('An agent must build AgentResources.');
    }
}
