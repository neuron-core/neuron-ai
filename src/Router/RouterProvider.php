<?php

declare(strict_types=1);

namespace NeuronAI\Router;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Router\Rules\RoutingRuleInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ToolInterface;

use function implode;
use function is_array;
use function array_keys;

class RouterProvider implements AIProviderInterface
{
    use StaticConstructor;

    /**
     * @var array<string, AIProviderInterface>
     */
    protected array $providers = [];

    protected RoutingRuleInterface $rule;

    protected ?string $systemPrompt = null;

    /**
     * @var array<ToolInterface>
     */
    protected array $tools = [];

    public function addProvider(string $name, AIProviderInterface $provider): self
    {
        $this->providers[$name] = $provider;
        return $this;
    }

    public function setRule(RoutingRuleInterface $rule): self
    {
        $this->rule = $rule;
        return $this;
    }

    public function systemPrompt(string|array|null $prompt): AIProviderInterface
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
     */
    public function chat(Message ...$messages): ProviderResponse
    {
        $provider = $this->resolveProvider('chat', $messages);

        return $provider
            ->systemPrompt($this->systemPrompt)
            ->setTools($this->tools)
            ->chat(...$messages);
    }

    /**
     * @return Generator<int, TextChunk|ReasoningChunk|ToolCallChunk|array, mixed, ProviderResponse>
     * @throws ProviderException
     */
    public function stream(Message ...$messages): Generator
    {
        $provider = $this->resolveProvider('stream', $messages);

        return $provider
            ->systemPrompt($this->systemPrompt)
            ->setTools($this->tools)
            ->stream(...$messages);
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     * @throws ProviderException
     */
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        $provider = $this->resolveProvider(
            'structured',
            is_array($messages) ? $messages : [$messages],
        );

        return $provider
            ->systemPrompt($this->systemPrompt)
            ->setTools($this->tools)
            ->structured($messages, $class, $response_schema);
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new ProviderException(
            'RouterProvider does not provide a message mapper. The underlying provider handles message mapping.',
        );
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new ProviderException(
            'RouterProvider does not provide a tool payload mapper. The underlying provider handles tool payload mapping.',
        );
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
     *
     * @throws ProviderException
     */
    protected function resolveProvider(string $method, array $messages): AIProviderInterface
    {
        if (!isset($this->rule)) {
            throw new ProviderException(
                'RouterProvider: no routing strategy configured. Call setRule() to set one.',
            );
        }

        if ($this->providers === []) {
            throw new ProviderException(
                'RouterProvider: no providers registered. Call addProvider() to add one.',
            );
        }

        $name = $this->rule->resolveProvider($method, $messages, $this->tools);

        if (!isset($this->providers[$name])) {
            throw new ProviderException(
                "RouterProvider: unknown provider '{$name}'. Available: " . implode(', ', array_keys($this->providers)),
            );
        }

        return $this->providers[$name];
    }
}
