<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use function array_values;
use function count;

class RoundRobinRule implements RoutingRuleInterface
{
    /** @var string[] */
    protected array $providers;

    protected int $index = 0;

    public function __construct(array $providers)
    {
        $this->providers = array_values($providers);
    }

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        $name = $this->providers[$this->index];

        $this->index = ($this->index + 1) % count($this->providers);

        return $name;
    }
}
