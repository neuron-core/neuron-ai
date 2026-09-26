<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Ollama;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Ollama\MessageMapper;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\Ollama\ToolMapper;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class OllamaChatTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ANSWER = '{"model":"llama3.2","message":{"role":"assistant","content":"Hi"},"done_reason":"stop","done":true,"prompt_eval_count":21,"eval_count":6}';

    protected function provider(string ...$responses): Ollama
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = new Response(200, body: $response);
        }

        $provider = new Ollama('http://ollama.internal:11434/api/', 'llama3.2', httpClient: $this->recordingClient(...$queue));
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_chat_targets_the_configured_host_without_credentials(): void
    {
        $this->provider(self::ANSWER)->chat(new UserMessage('Hello'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST http://ollama.internal:11434/api/chat'], $this->sentTargets());
        $this->assertFalse($request->hasHeader('Authorization'));
        $this->assertFalse($this->sentBody()['stream']);
    }

    public function test_answer_usage_and_done_reason_are_mapped(): void
    {
        $message = $this->provider(self::ANSWER)->chat(new UserMessage('Hello'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Hi', $message->getContent());
        $this->assertSame('stop', $message->stopReason());
        $this->assertSame([21, 6], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_answer_without_counters_has_no_usage_nor_stop_reason(): void
    {
        $message = $this->provider('{"message":{"role":"assistant","content":"Hi"},"done":true,"prompt_eval_count":3}')
            ->chat(new UserMessage('Hello'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNull($message->getUsage());
        $this->assertNull($message->stopReason());
    }

    public function test_tool_calls_keep_the_accompanying_text(): void
    {
        $message = $this->provider('{"message":{"role":"assistant","content":"Checking","tool_calls":[{"function":{"name":"lookup","arguments":{"q":"rome"}}}]},"done":true}')
            ->chat(new UserMessage('Where?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking', $message->getContent());
        [$call] = $message->getToolCalls();
        $this->assertSame(['lookup', ['q' => 'rome'], 'Look it up'], [$call->getName(), $call->getInputs(), $call->getDescription()]);
    }

    public function test_tool_call_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider('{"message":{"role":"assistant","content":"","tool_calls":[{"function":{"name":"shell","arguments":{}}}]},"done":true}');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: shell.');

        $provider->chat(new UserMessage('Run'));
    }

    public function test_http_error_is_raised_as_http_exception(): void
    {
        $provider = new Ollama('http://ollama.internal:11434/api', 'missing-model', httpClient: $this->recordingClient(
            new Response(404, body: '{"error":"model \'missing-model\' not found"}'),
        ));

        try {
            $provider->chat(new UserMessage('Hi'));
            $this->fail('A 404 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->response?->statusCode);
            $this->assertStringContainsString("model 'missing-model' not found", $exception->getMessage());
        }
    }

    public function test_non_successful_response_the_client_does_not_raise_is_a_provider_exception(): void
    {
        // A redirect without Location reaches the provider instead of being followed or raised.
        $provider = new Ollama('http://ollama.internal:11434/api', 'llama3.2', httpClient: $this->recordingClient(
            new Response(302, body: 'Moved to the login page'),
        ));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Ollama chat error: Moved to the login page');

        $provider->chat(new UserMessage('Hi'));
    }

    public function test_each_request_sends_only_its_own_messages(): void
    {
        $provider = $this->provider(self::ANSWER, self::ANSWER);

        $provider->chat(new UserMessage('First'));
        $provider->chat(new UserMessage('Second'));

        $this->assertSame([['role' => 'user', 'content' => 'Second']], $this->sentBody(1)['messages']);
    }

    public function test_structured_output_sends_the_schema_as_format_only_for_that_call(): void
    {
        $provider = $this->provider(self::ANSWER, self::ANSWER);
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];

        $provider->structured(new UserMessage('Who?'), 'Person', $schema);
        $provider->chat(new UserMessage('Plain'));

        $this->assertSame($schema, $this->sentBody(0)['format']);
        $this->assertArrayNotHasKey('format', $this->sentBody(1));
    }

    public function test_structured_output_restores_parameters_when_the_request_fails(): void
    {
        $provider = new Ollama('http://ollama.internal:11434/api', 'llama3.2', ['keep_alive' => '5m'], $this->recordingClient(
            new Response(500, body: '{"error":"boom"}'),
            new Response(200, body: self::ANSWER),
        ));

        try {
            $provider->structured([new UserMessage('Who?')], 'Person', ['type' => 'object']);
            $this->fail('A 500 response must raise an HttpException.');
        } catch (HttpException) {
        }
        $provider->chat(new UserMessage('Plain'));

        $this->assertArrayNotHasKey('format', $this->sentBody(1));
        $this->assertSame('5m', $this->sentBody(1)['keep_alive']);
    }

    public function test_provider_tools_are_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Ollama does not support Provider Tools');

        (new ToolMapper())->map([new ProviderTool('web_search')]);
    }

    public function test_tool_parameters_extend_the_function_definition(): void
    {
        $mapped = (new ToolMapper())->map([(new ToolStub('lookup', 'Look it up'))->setParameters(['strict' => true])]);

        $this->assertTrue($mapped[0]['function']['strict']);
        $this->assertSame('lookup', $mapped[0]['function']['name']);
    }

    public function test_text_blocks_are_joined_and_unsupported_blocks_are_dropped(): void
    {
        $message = new UserMessage([
            new TextContent('First. '),
            new FileContent('JVBERi0=', SourceType::BASE64, 'application/pdf'),
            new ImageContent('iVBORw0=', SourceType::BASE64, 'image/png'),
            new TextContent('Second.'),
        ]);

        $this->assertSame([[
            'role' => 'user',
            'content' => 'First. Second.',
            'images' => ['iVBORw0='],
        ]], (new MessageMapper())->map([$message]));
    }

    public function test_tool_results_become_one_text_tool_message_per_call(): void
    {
        $message = new ToolResultMessage([
            ToolCall::make('lookup', 'a')->setResult('first'),
            ToolCall::make('lookup', 'b')->setResult(['nested' => 'array']),
        ]);

        $this->assertSame(
            '[{"role":"tool","content":"first"},{"role":"tool","content":"{\"nested\":\"array\"}"}]',
            json_encode((new MessageMapper())->map([$message]), JSON_THROW_ON_ERROR),
        );
    }
}
