<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
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
use function mb_strlen;
use function mb_substr;
use function uniqid;

class FakeAIProvider implements AIProviderInterface
{
    use StaticConstructor;
    use HandleWithTools;

    protected ?string $systemPrompt = null;

    /** @var Message[] */
    protected array $responseQueue = [];

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

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * @throws ProviderException
     */
    public function chat(Message ...$messages): Message
    {
        $response = $this->nextResponse();

        $this->recorded[] = new RequestRecord(
            method: 'chat',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
        );

        return $response;
    }

    /**
     * @return Generator<int, TextChunk, mixed, Message>
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
     * @return Generator<int, TextChunk, mixed, Message>
     */
    protected function streamChunks(Message $response): Generator
    {
        $text = $response->getContent() ?? '';
        $messageId = uniqid('fake_msg_');
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, $this->streamChunkSize);
            yield new TextChunk($messageId, $chunk);
            $offset += $this->streamChunkSize;
        }

        return $response;
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     * @throws ProviderException
     */
    public function structured(array|Message $messages, string $class, array $response_schema): Message
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

        return $response;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new FakeMessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new FakeToolMapper();
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    /**
     * Add more responses to the queue.
     */
    public function addResponses(Message ...$responses): self
    {
        foreach ($responses as $response) {
            $this->responseQueue[] = $response;
        }
        return $this;
    }

    /**
     * Set the chunk size for stream simulation.
     */
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
     * Assert that at least one recorded call matches the given callback.
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

    /**
     * Assert a specific method was called a given number of times.
     */
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
     * Assert the system prompt was set to the expected value on at least one call.
     */
    public function assertSystemPrompt(string $expected): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            if ($record->systemPrompt === $expected) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request had the expected system prompt.');
    }

    /**
     * Assert that tools were configured by name on at least one call.
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
