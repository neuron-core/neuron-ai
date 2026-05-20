<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;

interface RoutingRuleInterface
{
    /**
     * @param Message[] $messages
     * @param ToolInterface[] $tools
     */
    public function resolveProvider(string $method, array $messages, array $tools): string;
}
