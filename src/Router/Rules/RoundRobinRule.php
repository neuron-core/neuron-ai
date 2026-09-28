<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use InvalidArgumentException;

use function array_fill;
use function array_key_exists;
use function array_merge;
use function array_sum;
use function array_values;
use function count;
use function is_int;

// Extended round-robin to optionally support weighted load balancing so traffic
// can be distributed by percentage while preserving legacy sequential behavior.
class RoundRobinRule implements RoutingRuleInterface
{
    /** @var string[] */
    protected array $providers;

    protected int $index = 0;

    protected bool $useLoadBalancing;

    /** @var array<string, int> */
    protected array $providerWeights = [];

    /** @var string[] */
    protected array $weightedProviders = [];

    /**
     * @param string[] $providers
     * @param array<string, int> $providerWeights
     */
    public function __construct(array $providers, bool $useLoadBalancing = false, array $providerWeights = [])
    {
        $this->providers = array_values($providers);
        $this->useLoadBalancing = $useLoadBalancing;

        if ($providerWeights !== []) {
            $this->setProviderWeights($providerWeights);
        }
    }

    /**
     * @return string[]
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    public function setUseLoadBalancing(bool $enabled): self
    {
        $this->useLoadBalancing = $enabled;
        $this->index = 0;

        if (!$enabled) {
            $this->weightedProviders = [];
        }

        return $this;
    }

    /**
     * @param array<string, int> $providerWeights
     */
    public function setProviderWeights(array $providerWeights): self
    {
        $this->assertValidWeights($providerWeights);

        $weightedProviders = [];

        foreach ($this->providers as $name) {
            $weight = $providerWeights[$name];
            $weightedProviders = array_merge($weightedProviders, array_fill(0, $weight, $name));
        }

        $this->providerWeights = $providerWeights;
        $this->weightedProviders = $weightedProviders;
        $this->index = 0;

        return $this;
    }

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        $pool = $this->useLoadBalancing && $this->weightedProviders !== []
            ? $this->weightedProviders
            : $this->providers;

        if ($pool === []) {
            throw new InvalidArgumentException('RoundRobinRule: providers list cannot be empty.');
        }

        $name = $pool[$this->index];
        $this->index = ($this->index + 1) % count($pool);

        return $name;
    }

    /**
     * @param array<string, int> $providerWeights
     */
    protected function assertValidWeights(array $providerWeights): void
    {
        if ($providerWeights === []) {
            throw new InvalidArgumentException('RoundRobinRule: providerWeights cannot be empty when load balancing is enabled.');
        }

        foreach ($this->providers as $name) {
            if (!array_key_exists($name, $providerWeights)) {
                throw new InvalidArgumentException("RoundRobinRule: missing weight for provider '{$name}'.");
            }

            if (!is_int($providerWeights[$name]) || $providerWeights[$name] <= 0) {
                throw new InvalidArgumentException("RoundRobinRule: weight for provider '{$name}' must be a positive integer.");
            }
        }

        if (array_sum($providerWeights) !== 100) {
            throw new InvalidArgumentException('RoundRobinRule: provider weights must sum to 100.');
        }
    }
}
