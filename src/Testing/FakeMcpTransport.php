<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\MCP\McpTransportInterface;
use PHPUnit\Framework\Assert;

use function array_shift;
use function count;

class FakeMcpTransport implements McpTransportInterface
{
    /** @var array<string, mixed>[] */
    protected array $responseQueue = [];

    /** @var array<string, mixed>[] */
    protected array $sent = [];

    /** @var array<string, mixed>[] */
    protected array $received = [];

    protected bool $connected = false;

    protected int $receiveCallCount = 0;

    protected int $sendCallCount = 0;

    /**
     * @param  array<string, mixed>  ...$responses  Predetermined responses to return sequentially from receive()
     */
    public function __construct(array ...$responses)
    {
        $this->responseQueue = $responses;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function __serialize(): array
    {
        return [
            'queue' => [
                ...$this->sent,
                ...$this->responseQueue,
            ],
            'connected' => $this->connected,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->responseQueue = $data['queue'];
        $this->connected = $data['connected'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(array $data): void
    {
        $this->sent[] = $data;
        $this->sendCallCount++;
    }

    /**
     * @return array<string, mixed>
     */
    public function receive(): array
    {
        $this->receiveCallCount++;

        if ($this->responseQueue === []) {
            Assert::fail('FakeMcpTransport response queue is empty. Add more responses with addResponses() or pass them to the constructor.');
        }

        $response = array_shift($this->responseQueue);

        $this->received[] = $response;

        return $response;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    /**
     * Add more responses to the queue.
     *
     * @param  array<string, mixed>  ...$responses
     */
    public function addResponses(array ...$responses): self
    {
        foreach ($responses as $response) {
            $this->responseQueue[] = $response;
        }

        return $this;
    }

    /**
     * Get all sent data.
     *
     * @return array<string, mixed>[]
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * Get all received data.
     *
     * @return array<string, mixed>[]
     */
    public function getReceived(): array
    {
        return $this->received;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getSendCallCount(): int
    {
        return $this->sendCallCount;
    }

    public function getReceiveCallCount(): int
    {
        return $this->receiveCallCount;
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    public function assertConnected(): void
    {
        Assert::assertTrue($this->connected, 'Transport should be connected.');
    }

    public function assertDisconnected(): void
    {
        Assert::assertFalse($this->connected, 'Transport should be disconnected.');
    }

    public function assertSendCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->sent,
            "Expected {$expected} send() calls, got ".count($this->sent).'.'
        );
    }

    public function assertReceiveCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->received,
            "Expected {$expected} receive() calls, got ".count($this->received).'.'
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->sent,
            'Expected no data to be sent, but '.count($this->sent).' sends were recorded.'
        );
    }

    public function assertNothingReceived(): void
    {
        Assert::assertEmpty(
            $this->received,
            'Expected no data to be received, but '.count($this->received).' receives were recorded.'
        );
    }

    /**
     * Assert that at least one sent request matches the given callback.
     *
     * @param  callable(array<string, mixed>): bool  $callback
     */
    public function assertSent(callable $callback): void
    {
        $matched = false;

        foreach ($this->sent as $data) {
            if ($callback($data)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No sent data matched the given assertion callback.');
    }

    /**
     * Assert that a specific method was sent.
     */
    public function assertMethodSent(string $method, int $expectedCount = 1): void
    {
        $count = 0;

        foreach ($this->sent as $data) {
            if (($data['method'] ?? null) === $method) {
                $count++;
            }
        }

        Assert::assertSame(
            $expectedCount,
            $count,
            "Expected {$expectedCount} sends with method '{$method}', got {$count}."
        );
    }

    /**
     * Assert that a specific method was received.
     */
    public function assertMethodReceived(string $method, int $expectedCount = 1): void
    {
        $count = 0;

        foreach ($this->received as $data) {
            if (($data['result']['method'] ?? null) === $method || ($data['method'] ?? null) === $method) {
                $count++;
            }
        }

        Assert::assertSame(
            $expectedCount,
            $count,
            "Expected {$expectedCount} receives with method '{$method}', got {$count}."
        );
    }

    /**
     * Assert that the initialize sequence was called correctly.
     */
    public function assertInitialized(): void
    {
        $this->assertMethodSent('initialize', 1);
        $this->assertMethodSent('notifications/initialized', 1);
    }

    /**
     * Assert that tools/list was called.
     */
    public function assertToolsListCalled(int $expectedCount = 1): void
    {
        $this->assertMethodSent('tools/list', $expectedCount);
    }

    /**
     * Assert that a specific tool was called.
     */
    public function assertToolCalled(string $toolName, int $expectedCount = 1): void
    {
        $count = 0;

        foreach ($this->sent as $data) {
            if (($data['method'] ?? null) === 'tools/call' && ($data['params']['name'] ?? null) === $toolName) {
                $count++;
            }
        }

        Assert::assertSame(
            $expectedCount,
            $count,
            "Expected {$expectedCount} calls to tool '{$toolName}', got {$count}."
        );
    }
}
