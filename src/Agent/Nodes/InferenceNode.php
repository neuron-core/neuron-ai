<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Workflow\Node;

/**
 * Base class for the provider-inference nodes an Agent composes per execution mode
 * (ChatNode, StreamingNode, StructuredOutputNode).
 *
 * The three modes are siblings, not subclasses of one another. Without a shared
 * base, node-specific middleware attached to one concrete class (e.g. ChatNode)
 * silently does nothing in the other modes. Because middleware matching is
 * subclass-aware (instanceof), attaching middleware to InferenceNode::class
 * targets every inference mode at once.
 */
abstract class InferenceNode extends Node
{
}
