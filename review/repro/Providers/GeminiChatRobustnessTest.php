<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use ErrorException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;

class GeminiChatRobustnessTest extends TestCase
{
    protected function setUp(): void
    {
        // Applications commonly promote warnings to exceptions (Laravel, Symfony).
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    protected function provider(string $body): Gemini
    {
        return (new Gemini('key', 'gemini-2.5-flash', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        )))->setTools([new ToolStub('get_time')]);
    }

    public function test_function_call_without_args_has_empty_inputs(): void
    {
        $message = $this->provider('{"candidates":[{"content":{"parts":[{"functionCall":{"name":"get_time"}}]},"finishReason":"STOP"}]}')
            ->chat(new UserMessage('Time?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame([], $message->getToolCalls()[0]->getInputs());
    }

    public function test_empty_content_with_stop_returns_an_empty_message(): void
    {
        $message = $this->provider('{"candidates":[{"content":{"role":"model"},"finishReason":"STOP"}]}')
            ->chat(new UserMessage('Hi'))->message();

        $this->assertSame([], $message->getContentBlocks());
        $this->assertSame('STOP', $message->stopReason());
    }

    public function test_missing_content_with_stop_returns_an_empty_message(): void
    {
        $message = $this->provider('{"candidates":[{"finishReason":"STOP"}]}')
            ->chat(new UserMessage('Hi'))->message();

        $this->assertSame([], $message->getContentBlocks());
        $this->assertSame('STOP', $message->stopReason());
    }

    public function test_usage_without_prompt_token_count_defaults_to_zero(): void
    {
        $message = $this->provider('{"candidates":[{"content":{"parts":[{"text":"a"}]},"finishReason":"STOP"}],"usageMetadata":{"candidatesTokenCount":3}}')
            ->chat(new UserMessage('Hi'))->message();

        $this->assertSame(0, $message->getUsage()->inputTokens);
        $this->assertSame(3, $message->getUsage()->outputTokens);
    }
}
