<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;

// Added to provide a local routing contract so Neuron can route across providers
// without relying on the external router package.
interface RoutingRuleInterface
{
    /**
     * @param Message[] $messages
     * @param ToolInterface[] $tools
     */
    public function resolveProvider(string $method, array $messages, array $tools): string;
}
