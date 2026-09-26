<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Mistral;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class MistralTest extends TestCase
{
    use RecordsHttpRequests;

    protected const SECRET = 'mistral-SECRET-0123456789';

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $parameters
     */
    protected function provider(array $response, array $parameters = []): Mistral
    {
        $provider = new Mistral(self::SECRET, 'mistral-large-latest', $parameters, httpClient: $this->recordingClient(
            new Response(200, body: json_encode($response, JSON_THROW_ON_ERROR)),
        ));
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    protected static function completion(array $message, string $finishReason = 'stop'): array
    {
        return [
            'id' => 'cmpl-1',
            'choices' => [['index' => 0, 'finish_reason' => $finishReason, 'message' => ['role' => 'assistant', ...$message]]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4, 'total_tokens' => 15],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_request_shape_endpoint_and_bearer_authentication(): void
    {
        $provider = $this->provider(self::completion(['content' => 'Hi!']), ['temperature' => 0.3]);
        $provider->setTools([(new ToolStub('lookup', 'Look it up'))->addProperty(new ToolProperty('q', PropertyType::STRING, 'Query', true))]);
        $provider->systemPrompt('Be kind');

        $provider->chat(new UserMessage('Hello'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.mistral.ai/v1/chat/completions'], $this->sentTargets());
        $this->assertSame('Bearer '.self::SECRET, $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getBody());
        $this->assertSame([
            'model' => 'mistral-large-latest',
            'messages' => [
                ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Be kind']]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ],
            'temperature' => 0.3,
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'lookup',
                    'description' => 'Look it up',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['q' => ['type' => 'string', 'description' => 'Query']],
                        'required' => ['q'],
                    ],
                ],
            ]],
        ], $this->sentBody());
    }

    public function test_request_without_tools_or_system_prompt_omits_them(): void
    {
        $provider = $this->provider(self::completion(['content' => 'Hi!']));
        $provider->setTools([]);

        $provider->chat(new UserMessage('Hello'));

        $this->assertSame(['model', 'messages'], array_keys($this->sentBody()));
    }

    public function test_text_answer_with_usage_and_stop_reason(): void
    {
        $message = $this->provider(self::completion(['content' => 'Bonjour']))->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Bonjour', $message->getContent());
        $this->assertSame('stop', $message->stopReason());
        $this->assertSame([11, 4], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_tool_calls_answer_is_mapped_to_tool_call_message(): void
    {
        $toolCalls = [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"q":"paris"}']],
            ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']],
        ];

        $message = $this->provider(self::completion(['content' => 'Looking up', 'tool_calls' => $toolCalls], 'tool_calls'))
            ->chat(new UserMessage('Where?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Looking up', $message->getContent());
        $this->assertSame('tool_calls', $message->stopReason());
        $this->assertSame($toolCalls, $message->getMetadata('tool_calls'));
        [$first, $second] = $message->getToolCalls();
        $this->assertSame(['lookup', 'call_a', ['q' => 'paris'], 'Look it up'], [$first->getName(), $first->getCallId(), $first->getInputs(), $first->getDescription()]);
        $this->assertSame(['call_b', []], [$second->getCallId(), $second->getInputs()]);
    }

    public function test_tool_call_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider(self::completion([
            'content' => '',
            'tool_calls' => [['id' => 'x', 'type' => 'function', 'function' => ['name' => 'exec', 'arguments' => '{}']]],
        ], 'tool_calls'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: exec.');

        $provider->chat(new UserMessage('Run'));
    }

    public function test_http_error_does_not_expose_the_api_key(): void
    {
        $provider = new Mistral(self::SECRET, 'mistral-large-latest', httpClient: $this->recordingClient(
            new Response(401, body: '{"message":"Unauthorized","request_id":"r1"}'),
        ));

        try {
            $provider->chat(new UserMessage('Hi'));
            $this->fail('A 401 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringContainsString('Unauthorized', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }
}
