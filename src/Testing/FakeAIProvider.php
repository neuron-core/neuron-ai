<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Assert;

use function array_map;
use function array_shift;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function mb_str_split;

use const JSON_THROW_ON_ERROR;

class FakeAIProvider implements AIProviderInterface
{
    use StaticConstructor;
    use HandleWithTools;

    protected string $model = 'fake';

    protected ?SystemMessage $systemPrompt = null;

    /** @var Message[] */
    protected array $responseQueue;

    /** @var RequestRecord[] */
    protected array $recorded = [];

    protected int $streamChunkSize = 5;

    /**
     * @param Message ...$responses Predetermined responses to return sequentially
     */
    public function __construct(Message ...$responses)
    {
        $this->responseQueue = $responses;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->systemPrompt = is_string($prompt) ? new SystemMessage($prompt) : $prompt;
        return $this;
    }

    /**
     * @throws ProviderException
     */
    public function chat(Message ...$messages): ProviderResponse
    {
        $response = $this->nextResponse();

        $this->recorded[] = new RequestRecord(
            method: 'chat',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
        );

        return new ProviderResponse(message: $response);
    }

    /**
     * @return Generator<int, StreamChunk, mixed, ProviderResponse>
     * @throws ProviderException
     */
    public function stream(Message ...$messages): Generator
    {
        // Eagerly shift from queue and record before returning the generator,
        // because generator bodies don't execute until iterated.
        $response = $this->nextResponse();

        $this->recorded[] = new RequestRecord(
            method: 'stream',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
        );

        return $this->streamChunks($response);
    }

    /**
     * Chunks are derived from the queued message the way a real provider
     * streams it: reasoning and text blocks in content order, then the JSON
     * inputs of every tool call.
     *
     * @return Generator<int, StreamChunk, mixed, ProviderResponse>
     */
    protected function streamChunks(Message $response): Generator
    {
        $messageId = $response->getId();

        foreach ($response->getContentBlocks() as $block) {
            if (!$block instanceof TextContent) {
                continue;
            }

            foreach ($this->split($block->content) as $piece) {
                yield $block instanceof ReasoningContent
                    ? new ReasoningChunk($messageId, $piece)
                    : new TextChunk($messageId, $piece);
            }
        }

        if ($response instanceof ToolCallMessage) {
            foreach ($response->getToolCalls() as $call) {
                $arguments = json_encode((object) $call->getInputs(), JSON_THROW_ON_ERROR);

                foreach ($this->split($arguments) as $piece) {
                    yield new ToolArgumentChunk($messageId, $call->getName(), $piece, $call->getCallId());
                }
            }
        }

        return new ProviderResponse(message: $response);
    }

    /**
     * @return string[]
     */
    protected function split(string $text): array
    {
        return mb_str_split($text, $this->streamChunkSize);
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     * @throws ProviderException
     */
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        $messages = is_array($messages) ? $messages : [$messages];

        $response = $this->nextResponse();

        $this->recorded[] = new RequestRecord(
            method: 'structured',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
            structuredClass: $class,
            structuredSchema: $response_schema,
        );

        return new ProviderResponse(message: $response);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    public function addResponses(Message ...$responses): self
    {
        foreach ($responses as $response) {
            $this->responseQueue[] = $response;
        }
        return $this;
    }

    public function setStreamChunkSize(int $size): self
    {
        $this->streamChunkSize = $size;
        return $this;
    }

    /**
     * @return RequestRecord[]
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    public function getCallCount(): int
    {
        return count($this->recorded);
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    public function assertCallCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->recorded,
            "Expected {$expected} provider calls, got " . count($this->recorded) . '.'
        );
    }

    /**
     * Assert at least one recorded call matches the callback.
     *
     * @param callable(RequestRecord): bool $callback
     */
    public function assertSent(callable $callback): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            if ($callback($record)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request matched the given assertion callback.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no provider calls, but ' . count($this->recorded) . ' were recorded.'
        );
    }

    public function assertMethodCallCount(string $method, int $expected): void
    {
        $count = 0;
        foreach ($this->recorded as $record) {
            if ($record->method === $method) {
                $count++;
            }
        }

        Assert::assertSame(
            $expected,
            $count,
            "Expected {$expected} '{$method}' calls, got {$count}."
        );
    }

    /**
     * Assert the expected system prompt was set on at least one call.
     */
    public function assertSystemPrompt(string $expected): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            if ($record->systemPrompt?->getContent() === $expected) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request had the expected system prompt.');
    }

    /**
     * Assert the exact tool name list was configured on at least one call.
     *
     * @param string[] $toolNames
     */
    public function assertToolsConfigured(array $toolNames): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            $recordToolNames = array_values(array_map(
                static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(),
                $record->tools
            ));

            if (array_values($toolNames) === $recordToolNames) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request had the expected tools configured: ' . implode(', ', $toolNames));
    }

    protected function nextResponse(): Message
    {
        if ($this->responseQueue === []) {
            throw new ProviderException(
                'FakeAIProvider response queue is empty. Add more responses with addResponses() or pass them to the constructor.'
            );
        }

        return array_shift($this->responseQueue);
    }
}
