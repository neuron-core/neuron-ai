<?php

declare(strict_types=1);

namespace NeuronAI\Router;

use Closure;
use Generator;
use Throwable;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Router\Rules\RoundRobinRule;
use NeuronAI\Router\Rules\RoutingRuleInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;

use function array_key_last;
use function array_keys;
use function array_values;
use function implode;
use function in_array;
use function is_array;

// Added a built-in RouterProvider so this fork can route requests across
// multiple providers, with optional weighted load balancing on RoundRobinRule.
class RouterProvider implements AIProviderInterface
{
    use StaticConstructor;

    /**
     * @var array<string, AIProviderInterface>
     */
    protected array $providers = [];

    /**
     * @var list<string>
     */
    protected array $fallbackOrder = [];

    /**
     * @var Closure(Throwable): bool|null
     */
    protected ?Closure $fallbackStrategy = null;

    protected RoutingRuleInterface $rule;

    protected ?string $systemPrompt = null;

    protected ?AIProviderInterface $resolvedProvider = null;

    /**
     * @var array<ToolInterface>
     */
    protected array $tools = [];

    /**
     * @var array<string, array{useLoadBalancing: bool, utilizationRate: int|null}>
     */
    protected array $providerRoutingConfig = [];

    public function addProvider(
        string $name,
        AIProviderInterface $provider,
        bool $useLoadBalancing = false,
        ?int $utilizationRate = null,
    ): self {
        if ($useLoadBalancing && ($utilizationRate === null || $utilizationRate <= 0)) {
            throw new ProviderException(
                "RouterProvider: provider '{$name}' has load balancing enabled but utilizationRate is missing or invalid.",
            );
        }

        $this->providers[$name] = $provider;
        $this->providerRoutingConfig[$name] = [
            'useLoadBalancing' => $useLoadBalancing,
            'utilizationRate' => $utilizationRate,
        ];

        return $this;
    }

    public function setRule(RoutingRuleInterface $rule): self
    {
        if ($rule instanceof RoundRobinRule) {
            $this->configureRoundRobinLoadBalancing($rule);
        }

        $this->rule = $rule;
        return $this;
    }

    /**
     * @throws ProviderException
     */
    public function setDefaultProvider(string $name): self
    {
        if (!isset($this->providers[$name])) {
            throw new ProviderException(
                "RouterProvider: unknown provider '{$name}'. Available: " . implode(', ', array_keys($this->providers)),
            );
        }
        $this->resolvedProvider = $this->providers[$name];
        return $this;
    }

    /**
     * @throws ProviderException
     */
    public function setFallbackOrder(string ...$names): self
    {
        foreach ($names as $name) {
            if (!isset($this->providers[$name])) {
                throw new ProviderException(
                    "RouterProvider: unknown fallback provider '{$name}'. Available: " . implode(', ', array_keys($this->providers)),
                );
            }
        }
        $this->fallbackOrder = array_values($names);
        return $this;
    }

    /**
     * @param callable(Throwable): bool $strategy
     */
    public function setFallbackStrategy(callable $strategy): self
    {
        $this->fallbackStrategy = static fn (Throwable $throwable): bool => $strategy($throwable);
        return $this;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        $this->tools = $tools;
        return $this;
    }

    /**
     * @throws ProviderException
     * @throws Throwable
     */
    public function chat(Message ...$messages): Message
    {
        return $this->withFallback(
            'chat',
            $messages,
            fn (AIProviderInterface $provider): Message => $provider->chat(...$messages),
        );
    }

    /**
     * @return Generator<int, StreamChunk, mixed, Message>
     * @throws ProviderException
     * @throws Throwable
     */
    public function stream(Message ...$messages): Generator
    {
        $candidates = $this->candidates('stream', $messages);

        foreach ($candidates as $index => $name) {
            $isLast = $index === array_key_last($candidates);
            $this->resolvedProvider = $this->providers[$name];
            $generator = $this->prepare($name)->stream(...$messages);

            try {
                $generator->rewind();
            } catch (Throwable $e) {
                if (!$this->canFallback($e) || $isLast) {
                    throw $e;
                }
                continue;
            }

            return $this->replay($generator);
        }

        throw new ProviderException('RouterProvider: all providers failed.');
    }

    /**
     * @param Generator<int, StreamChunk, mixed, Message> $primed
     * @return Generator<int, StreamChunk, mixed, Message>
     */
    protected function replay(Generator $primed): Generator
    {
        while ($primed->valid()) {
            yield $primed->current();
            $primed->next();
        }

        return $primed->getReturn();
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     * @throws ProviderException
     * @throws Throwable
     */
    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        return $this->withFallback(
            'structured',
            is_array($messages) ? $messages : [$messages],
            fn (AIProviderInterface $provider): Message => $provider->structured($messages, $class, $response_schema),
        );
    }

    /**
     * @throws ProviderException
     */
    public function messageMapper(): MessageMapperInterface
    {
        if (!$this->resolvedProvider instanceof AIProviderInterface) {
            throw new ProviderException(
                'RouterProvider: no provider available for delegation. Call setDefaultProvider() or make an inference call first.',
            );
        }
        return $this->resolvedProvider->messageMapper();
    }

    /**
     * @throws ProviderException
     */
    public function toolPayloadMapper(): ToolMapperInterface
    {
        if (!$this->resolvedProvider instanceof AIProviderInterface) {
            throw new ProviderException(
                'RouterProvider: no provider available for delegation. Call setDefaultProvider() or make an inference call first.',
            );
        }
        return $this->resolvedProvider->toolPayloadMapper();
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        foreach ($this->providers as $provider) {
            $provider->setHttpClient($client);
        }
        return $this;
    }

    /**
     * @param Message[] $messages
     * @return list<string>
     * @throws ProviderException
     */
    protected function candidates(string $method, array $messages): array
    {
        if ($this->providers === []) {
            throw new ProviderException(
                'RouterProvider: no providers registered. Call addProvider() to add one.',
            );
        }

        if (!isset($this->rule)) {
            if ($this->fallbackOrder === []) {
                throw new ProviderException(
                    'RouterProvider: no routing strategy configured. Call setRule() to set one, or setFallbackOrder() to route without a rule.',
                );
            }

            return $this->fallbackOrder;
        }

        $primary = $this->resolveProviderName($method, $messages);
        $candidates = [$primary];

        foreach ($this->fallbackOrder as $name) {
            if (!in_array($name, $candidates, true)) {
                $candidates[] = $name;
            }
        }

        return $candidates;
    }

    /**
     * @param Message[] $messages
     * @throws ProviderException
     */
    protected function resolveProviderName(string $method, array $messages): string
    {
        $name = $this->rule->resolveProvider($method, $messages, $this->tools);

        if (!isset($this->providers[$name])) {
            throw new ProviderException(
                "RouterProvider: unknown provider '{$name}'. Available: " . implode(', ', array_keys($this->providers)),
            );
        }

        return $name;
    }

    /**
     * @param Message[] $messages
     * @param callable(AIProviderInterface): Message $callback
     * @throws Throwable
     */
    protected function withFallback(string $method, array $messages, callable $callback): Message
    {
        $candidates = $this->candidates($method, $messages);

        foreach ($candidates as $index => $name) {
            $isLast = $index === array_key_last($candidates);
            $this->resolvedProvider = $this->providers[$name];

            try {
                return $callback($this->prepare($name));
            } catch (Throwable $e) {
                if (!$this->canFallback($e) || $isLast) {
                    throw $e;
                }
            }
        }

        throw new ProviderException('RouterProvider: all providers failed.');
    }

    protected function prepare(string $name): AIProviderInterface
    {
        return $this->providers[$name]
            ->systemPrompt($this->systemPrompt)
            ->setTools($this->tools);
    }

    protected function canFallback(Throwable $e): bool
    {
        if ($this->fallbackStrategy instanceof Closure) {
            return ($this->fallbackStrategy)($e);
        }

        return $this->defaultFallbackStrategy($e);
    }

    protected function defaultFallbackStrategy(Throwable $e): bool
    {
        if (!$e instanceof HttpException) {
            return false;
        }

        $status = $e->response?->statusCode;

        return $status === null || $status === 429 || $status >= 500;
    }

    /**
     * Reads per-provider routing flags and, when present for all providers in the
     * round-robin rule, upgrades it to weighted load balancing.
     *
     * @throws ProviderException
     */
    protected function configureRoundRobinLoadBalancing(RoundRobinRule $rule): void
    {
        $providers = $rule->getProviders();
        $weights = [];

        foreach ($providers as $name) {
            if (!isset($this->providers[$name])) {
                throw new ProviderException(
                    "RouterProvider: unknown provider '{$name}' in RoundRobinRule. Available: " . implode(', ', array_keys($this->providers)),
                );
            }

            $config = $this->providerRoutingConfig[$name] ?? [
                'useLoadBalancing' => false,
                'utilizationRate' => null,
            ];

            if ($config['useLoadBalancing']) {
                if ($config['utilizationRate'] === null || $config['utilizationRate'] <= 0) {
                    throw new ProviderException(
                        "RouterProvider: provider '{$name}' has invalid utilizationRate for load balancing.",
                    );
                }

                $weights[$name] = $config['utilizationRate'];
            }
        }

        if ($weights === []) {
            $rule->setUseLoadBalancing(false);
            return;
        }

        if (count($weights) !== count($providers)) {
            throw new ProviderException(
                'RouterProvider: when enabling load balancing in RoundRobinRule, all listed providers must define useLoadBalancing=true and utilizationRate.',
            );
        }

        $rule
            ->setUseLoadBalancing(true)
            ->setProviderWeights($weights);
    }
}
