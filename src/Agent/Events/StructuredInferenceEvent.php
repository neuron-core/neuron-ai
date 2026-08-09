<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Events;

/**
 * Routing marker for structured-output inference: exact-class event routing
 * sends this to StructuredOutputNode while its parent class routes to the
 * chat/stream node. Its configuration is the inherited inference intent
 * fields (outputClass, maxTries).
 */
class StructuredInferenceEvent extends AIInferenceEvent
{
}
