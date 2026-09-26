<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;
use NeuronAI\Exceptions\HttpException;

use function array_column;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class AnthropicChatTest extends TestCase
{
    use RecordsHttpRequests;

    protected function provider(array $response, array $parameters = []): Anthropic
    {
        return new Anthropic(
            key: 'sk-ant-test',
            model: 'claude-test',
            parameters: $parameters,
            httpClient: $this->recordingClient(new Response(200, body: json_encode($response, JSON_THROW_ON_ERROR))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_request_targets_the_messages_endpoint_with_api_key_and_version_headers(): void
    {
        $provider = new Anthropic(
            key: 'sk-ant-test',
            model: 'claude-test',
            version: '2025-01-01',
            httpClient: $this->recordingClient(new Response(200, body: '{"content":[]}')),
        );

        $provider->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.anthropic.com/v1/messages'], $this->sentTargets());
        $this->assertSame('sk-ant-test', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2025-01-01', $request->getHeaderLine('anthropic-version'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertFalse($request->hasHeader('Authorization'));
    }

    public function test_system_prompt_is_sent_top_level_and_never_as_a_message(): void
    {
        $provider = $this->provider(['content' => []])->systemPrompt('Be concise');

        $provider->chat(new UserMessage('Hi'));

        $body = $this->sentBody();
        $this->assertSame([['type' => 'text', 'text' => 'Be concise']], $body['system']);
        $this->assertSame(['user'], array_column($body['messages'], 'role'));
    }

    public function test_null_system_prompt_removes_the_system_field(): void
    {
        $provider = $this->provider(['content' => []])->systemPrompt('Be concise')->systemPrompt(null);

        $provider->chat(new UserMessage('Hi'));

        $this->assertArrayNotHasKey('system', $this->sentBody());
    }

    public function test_max_tokens_defaults_to_8192_and_follows_the_constructor(): void
    {
        $default = new Anthropic('sk-ant-test', 'claude-test', httpClient: $this->recordingClient(new Response(200, body: '{"content":[]}')));
        $custom = new Anthropic('sk-ant-test', 'claude-test', max_tokens: 1500, httpClient: $this->recordingClient(new Response(200, body: '{"content":[]}')));

        $default->chat(new UserMessage('Hi'));
        $custom->chat(new UserMessage('Hi'));

        $this->assertSame(8192, $this->sentBody(0)['max_tokens']);
        $this->assertSame(1500, $this->sentBody(1)['max_tokens']);
    }

    public function test_custom_parameters_are_merged_into_the_request_body(): void
    {
        $provider = $this->provider(['content' => []], ['temperature' => 0.2, 'max_tokens' => 1024]);

        $provider->chat(new UserMessage('Hi'));

        $body = $this->sentBody();
        $this->assertSame(0.2, $body['temperature']);
        $this->assertSame(1024, $body['max_tokens']);
        $this->assertArrayNotHasKey('stream', $body);
    }

    public function test_tool_use_response_becomes_a_tool_call_message_that_keeps_the_text(): void
    {
        $provider = $this->provider([
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => 'Checking both cities.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'weather', 'input' => ['city' => 'Rome']],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'weather', 'input' => ['city' => 'Tōkyō']],
            ],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
        ]);
        $provider->setTools([new ToolStub('weather', 'Get the weather')]);

        $message = $provider->chat(new UserMessage('Weather?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking both cities.', $message->getContent());
        $calls = $message->getToolCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['toolu_1', 'toolu_2'], [$calls[0]->getCallId(), $calls[1]->getCallId()]);
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame(['city' => 'Tōkyō'], $calls[1]->getInputs());
        $this->assertSame('Get the weather', $calls[0]->getDescription());
        $this->assertSame([1, 2], $message->getMetadata('anthropic_tool_positions'));
        $this->assertSame('tool_use', $message->stopReason());
        $this->assertSame(12, $message->getUsage()->inputTokens);
        $this->assertSame(7, $message->getUsage()->outputTokens);
    }

    public function test_tool_use_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider([
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'drop_database', 'input' => []]],
        ]);
        $provider->setTools([new ToolStub('weather')]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('non-existing tool: drop_database');

        $provider->chat(new UserMessage('Weather?'));
    }

    public function test_thinking_blocks_keep_their_signature_and_order(): void
    {
        $provider = $this->provider([
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Reasoning', 'signature' => 'sig-1'],
                ['type' => 'text', 'text' => 'Answer'],
            ],
        ]);

        $blocks = $provider->chat(new UserMessage('Q'))->message()->getContentBlocks();

        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(ReasoningContent::class, $blocks[0]);
        $this->assertSame('Reasoning', $blocks[0]->content);
        $this->assertSame('sig-1', $blocks[0]->id);
        $this->assertInstanceOf(TextContent::class, $blocks[1]);
        $this->assertSame('Answer', $blocks[1]->content);
    }

    public function test_response_without_content_usage_or_stop_reason_is_an_empty_assistant_message(): void
    {
        $message = $this->provider([])->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame([], $message->getContentBlocks());
        $this->assertNull($message->getUsage());
        $this->assertNull($message->stopReason());
        $this->assertNull($message->getMetadata('citations'));
    }

    public function test_cache_metrics_are_only_attached_when_the_cache_was_used(): void
    {
        $message = $this->provider([
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 1, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
        ])->chat(new UserMessage('Hi'))->message();

        $this->assertSame(0, $message->getUsage()->cachedInputTokens);
        $this->assertNull($message->getMetadata('cacheWriteTokens'));
        $this->assertNull($message->getMetadata('cacheReadTokens'));
    }

    public function test_cache_write_sums_legacy_and_per_ttl_counters(): void
    {
        $message = $this->provider([
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'usage' => [
                'input_tokens' => 5,
                'output_tokens' => 1,
                'cache_creation_input_tokens' => 4,
                'cache_creation' => ['ephemeral_5m_input_tokens' => 3, 'ephemeral_1h_input_tokens' => 2],
            ],
        ])->chat(new UserMessage('Hi'))->message();

        $this->assertSame(9, $message->getMetadata('cacheWriteTokens'));
        $this->assertSame(0, $message->getMetadata('cacheReadTokens'));
    }

    public function test_provider_response_exposes_the_raw_body_and_headers(): void
    {
        $body = '{"content":[{"type":"text","text":"Hi"}]}';
        $provider = new Anthropic('sk-ant-test', 'claude-test', httpClient: $this->recordingClient(
            new Response(200, ['request-id' => 'req_123'], $body),
        ));

        $response = $provider->chat(new UserMessage('Hi'));

        $this->assertSame($body, $response->body());
        $this->assertSame(['req_123'], $response->headers()['request-id']);
    }

    public function test_structured_appends_the_schema_as_a_trailing_system_block(): void
    {
        $provider = $this->provider(['content' => [['type' => 'text', 'text' => '{"name":"Ada"}']]])
            ->systemPrompt(new SystemMessage([(new SystemContent('Cached rules'))->cache(), new SystemContent('Context')]));
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $response = $provider->structured(new UserMessage('Who?'), 'App\\Person', $schema);

        $system = $this->sentBody()['system'];
        $this->assertCount(3, $system);
        $this->assertSame(['type' => 'text', 'text' => 'Cached rules', 'cache_control' => ['type' => 'ephemeral']], $system[0]);
        $this->assertSame(['type' => 'text', 'text' => 'Context'], $system[1]);
        $this->assertSame(
            "# OUTPUT CONSTRAINTS\nYour response must be a JSON string following this schema: \n".json_encode($schema),
            $system[2]['text'],
        );
        $this->assertArrayNotHasKey('cache_control', $system[2]);
        $this->assertSame('{"name":"Ada"}', $response->message()->getContent());
    }

    public function test_structured_without_system_prompt_sends_only_the_schema(): void
    {
        $provider = $this->provider(['content' => []]);

        $provider->structured([new UserMessage('Who?')], 'Person', ['type' => 'object']);

        $system = $this->sentBody()['system'];
        $this->assertCount(1, $system);
        $this->assertStringEndsWith('{"type":"object"}', $system[0]['text']);
    }

    public function test_structured_restores_the_original_system_prompt(): void
    {
        $provider = new Anthropic('sk-ant-test', 'claude-test', httpClient: $this->recordingClient(
            new Response(200, body: '{"content":[]}'),
            new Response(200, body: '{"content":[]}'),
        ));
        $provider->systemPrompt('Original');

        $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object']);
        $provider->chat(new UserMessage('Hi'));

        $this->assertSame([['type' => 'text', 'text' => 'Original']], $this->sentBody(1)['system']);
    }

    public function test_structured_restores_the_system_prompt_when_the_request_fails(): void
    {
        $provider = new Anthropic('sk-ant-test', 'claude-test', httpClient: $this->recordingClient(
            new Response(500, body: '{"type":"error"}'),
            new Response(200, body: '{"content":[]}'),
        ));

        try {
            $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object']);
            $this->fail('The failing request must surface.');
        } catch (HttpException) {
        }
        $provider->chat(new UserMessage('Hi'));

        $this->assertArrayNotHasKey('system', $this->sentBody(1));
    }
}
