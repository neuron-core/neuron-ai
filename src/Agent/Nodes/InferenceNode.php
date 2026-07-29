<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Node;

/**
 * Base class for the nodes that perform the AI inference call.
 *
 * The Agent composes exactly one of ChatNode, StreamingNode, or
 * StructuredOutputNode depending on the invoked mode (chat, stream,
 * structured). Attach middleware to InferenceNode::class to have it
 * applied to the inference step regardless of the execution mode.
 */
abstract class InferenceNode extends Node
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
    ) {
    }
}
