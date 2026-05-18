<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that each provider surfaces the cached-input and reasoning token
 * counts from its usage payload onto the shared `Usage` value object.
 */
class UsageTokenDetailsTest extends TestCase
{
    private function client(string $body): GuzzleHttpClient
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(status: 200, body: $body),
        ]));

        return new GuzzleHttpClient(handler: $stack);
    }

    public function test_openai_chat_completions_extracts_cached_and_reasoning(): void
    {
        $body = '{"model":"gpt-5","choices":[{"index":0,"finish_reason":"stop",'
            .'"message":{"role":"assistant","content":"hi"}}],'
            .'"usage":{"prompt_tokens":100,"completion_tokens":20,"total_tokens":120,'
            .'"prompt_tokens_details":{"cached_tokens":40},'
            .'"completion_tokens_details":{"reasoning_tokens":12}}}';

        $provider = (new OpenAI('', 'gpt-5'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(12, $usage->reasoningTokens);
    }

    public function test_openai_chat_completions_defaults_to_zero_without_details(): void
    {
        $body = '{"model":"gpt-4o","choices":[{"index":0,"finish_reason":"stop",'
            .'"message":{"role":"assistant","content":"hi"}}],'
            .'"usage":{"prompt_tokens":19,"completion_tokens":10,"total_tokens":29}}';

        $provider = (new OpenAI('', 'gpt-4o'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(0, $usage->reasoningTokens);
    }

    public function test_openai_responses_extracts_cached_and_reasoning(): void
    {
        $body = '{"status":"completed","output":[{"type":"message",'
            .'"content":[{"type":"output_text","text":"hi"}]}],'
            .'"usage":{"input_tokens":100,"output_tokens":20,'
            .'"input_tokens_details":{"cached_tokens":40},'
            .'"output_tokens_details":{"reasoning_tokens":12}}}';

        $provider = (new OpenAIResponses('', 'gpt-5'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(12, $usage->reasoningTokens);
    }

    public function test_anthropic_maps_cache_read_to_cached_input_tokens(): void
    {
        $body = '{"model":"claude-3-7-sonnet-latest","role":"assistant",'
            .'"stop_reason":"end_turn","content":[{"type":"text","text":"hi"}],'
            .'"usage":{"input_tokens":100,"output_tokens":20,'
            .'"cache_read_input_tokens":40,"cache_creation_input_tokens":0}}';

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        // Anthropic has no reasoning-token field today.
        $this->assertSame(0, $usage->reasoningTokens);
    }

    public function test_gemini_extracts_cached_content_and_thoughts(): void
    {
        $body = '{"candidates":[{"content":{"role":"model",'
            .'"parts":[{"text":"hi"}]},"finishReason":"STOP"}],'
            .'"usageMetadata":{"promptTokenCount":100,"candidatesTokenCount":20,'
            .'"cachedContentTokenCount":40,"thoughtsTokenCount":12}}';

        $provider = (new Gemini('', 'gemini-2.5-flash'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(12, $usage->reasoningTokens);
    }
}
