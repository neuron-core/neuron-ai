<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Workflow\Graph;
use NeuronAI\Workflow\Workflow;
use ReflectionMethod;

/** Build a segment's graph and resources without persistence or traversal. */
final class ExecutionTestFactory
{
    public static function graph(Workflow $definition): Graph
    {
        $definition->setWorkflowId($definition->getWorkflowId() ?? 'test');

        return (new ReflectionMethod($definition, 'graph'))->invoke($definition, $definition->getStartEvent());
    }

    /** The resources an agent builds for a segment. */
    public static function agentResources(Agent $agent): AgentResources
    {
        $resources = self::graph($agent)->resources;

        return $resources instanceof AgentResources ? $resources : throw new LogicException('An agent must build AgentResources.');
    }
}
