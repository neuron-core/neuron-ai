<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolRegistry;
use NeuronAI\Workflow\WorkflowResources;

/**
 * What an agent run can use in one execution segment. The run's own working
 * prompt lives in the state; these instructions and this context are the base
 * a turn starts from.
 */
class AgentResources extends WorkflowResources
{
    /**
     * @param array<int|string, ContentBlockInterface> $context
     */
    public function __construct(
        public readonly AIProviderInterface $provider,
        public readonly ChatHistory $history,
        public readonly SystemMessage $instructions,
        public readonly ToolRegistry $tools = new ToolRegistry(),
        public readonly array $context = [],
    ) {
        parent::__construct();
    }
}
