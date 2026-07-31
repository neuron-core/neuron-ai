<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Node;

/**
 * Base for nodes that perform AI provider inference (chat, streaming,
 * structured output). Sharing a common ancestor lets middleware target
 * `InferenceNode::class` once and apply across every execution mode — the
 * Agent composes exactly one of these per mode, so attaching to any single
 * subclass would otherwise be dropped in the other two modes.
 */
abstract class InferenceNode extends Node implements AgentNodeInterface
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
        ChatHistoryInterface $chatHistory,
    ) {
        $this->chatHistory = $chatHistory;
    }
}
