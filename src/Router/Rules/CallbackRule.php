<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolInterface;
use Closure;

class CallbackRule implements RoutingRuleInterface
{
    protected Closure $callback;

    /**
     * @param callable(string $method, array<Message> $messages, array<ToolInterface> $tools): string $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        return ($this->callback)($method, $messages, $tools);
    }
}
