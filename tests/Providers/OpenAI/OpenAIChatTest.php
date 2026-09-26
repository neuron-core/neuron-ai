<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function array_column;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class OpenAIChatTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ANSWER = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer"}}]}';

    /**
     * @param array<string, mixed> $parameters
     */
    protected function provider(string $response = self::ANSWER, array $parameters = [], bool $strict = false): OpenAI
    {
        return new OpenAI('sk-test', 'gpt-test', $parameters, $strict, $this->recordingClient(new Response(200, body: $response)));
    }

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     */
    protected static function toolCallResponse(array $toolCalls, ?string $content = null): string
    {
        return json_encode(['choices' => [[
            'index' => 0,
            'finish_reason' => 'tool_calls',
            'message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls],
        ]]], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_request_targets_chat_completions_with_a_bearer_token(): void
    {
        $this->provider()->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.openai.com/v1/chat/completions'], $this->sentTargets());
        $this->assertSame('Bearer sk-test', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function test_system_prompt_is_prepended_as_the_first_message(): void
    {
        $provider = $this->provider()->systemPrompt(new SystemMessage('Be concise'));

        $provider->chat(new UserMessage('Hi'), new AssistantMessage('Hello'), new UserMessage('Bye'));

        $messages = $this->sentBody()['messages'];
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame([['type' => 'text', 'text' => 'Be concise']], $messages[0]['content']);
    }

    public function test_parameters_are_merged_and_tools_are_only_sent_when_registered(): void
    {
        $this->provider(parameters: ['temperature' => 0, 'max_completion_tokens' => 64])->chat(new UserMessage('Hi'));

        $body = $this->sentBody();
        $this->assertSame(0, $body['temperature']);
        $this->assertSame(64, $body['max_completion_tokens']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('stream', $body);
    }

    public function test_parallel_tool_calls_become_one_tool_call_message(): void
    {
        $toolCalls = [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Rome"}']],
            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Paris"}']],
        ];
        $provider = $this->provider(self::toolCallResponse($toolCalls, 'Checking both.'))->setTools([new ToolStub('weather', 'Weather')]);

        $message = $provider->chat(new UserMessage('Weather?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking both.', $message->getContent());
        $calls = $message->getToolCalls();
        $this->assertSame(['call_1', 'call_2'], [$calls[0]->getCallId(), $calls[1]->getCallId()]);
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame(['city' => 'Paris'], $calls[1]->getInputs());
        $this->assertSame($toolCalls, $message->getMetadata('tool_calls'));
        $this->assertSame('tool_calls', $message->stopReason());
    }

    public function test_tool_call_with_empty_arguments_has_empty_inputs(): void
    {
        $provider = $this->provider(self::toolCallResponse([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'now', 'arguments' => '']],
        ]))->setTools([new ToolStub('now')]);

        $message = $provider->chat(new UserMessage('Time?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertNull($message->getContent());
        $this->assertSame([], $message->getToolCalls()[0]->getInputs());
    }

    public function test_tool_call_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider(self::toolCallResponse([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'exfiltrate', 'arguments' => '{}']],
        ]))->setTools([new ToolStub('now')]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('non-existing tool: exfiltrate');

        $provider->chat(new UserMessage('Time?'));
    }

    public function test_truncated_answer_keeps_the_length_stop_reason_and_all_usage_counters(): void
    {
        $response = json_encode([
            'choices' => [['index' => 0, 'finish_reason' => 'length', 'message' => ['role' => 'assistant', 'content' => 'Partial']]],
            'usage' => [
                'prompt_tokens' => 11,
                'completion_tokens' => 64,
                'prompt_tokens_details' => ['cached_tokens' => 8],
                'completion_tokens_details' => ['reasoning_tokens' => 32],
            ],
        ], JSON_THROW_ON_ERROR);

        $message = $this->provider($response)->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Partial', $message->getContent());
        $this->assertSame('length', $message->stopReason());
        $this->assertSame([11, 64, 8, 32], [
            $message->getUsage()->inputTokens,
            $message->getUsage()->outputTokens,
            $message->getUsage()->cachedInputTokens,
            $message->getUsage()->reasoningTokens,
        ]);
    }

    public function test_structured_sends_a_named_json_schema_response_format(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $this->provider()->structured(new UserMessage('Who?'), 'App\\Dto\\Person', $schema);

        $this->assertSame([
            'type' => 'json_schema',
            'json_schema' => ['strict' => false, 'name' => 'Person', 'schema' => $schema],
        ], $this->sentBody()['response_format']);
    }

    public function test_structured_names_anonymous_classes_with_a_valid_identifier(): void
    {
        $class = (new class () {})::class;

        $this->provider()->structured(new UserMessage('Who?'), $class, ['type' => 'object']);

        $this->assertSame('anonymous', $this->sentBody()['response_format']['json_schema']['name']);
    }

    public function test_structured_prefixes_schema_names_that_do_not_start_with_a_letter(): void
    {
        $this->provider()->structured(new UserMessage('Who?'), 'App\\Dto\\_Draft', ['type' => 'object']);

        $this->assertSame('class__Draft', $this->sentBody()['response_format']['json_schema']['name']);
    }

    public function test_structured_keeps_user_response_format_options(): void
    {
        $provider = $this->provider(parameters: ['response_format' => ['json_schema' => ['description' => 'A person']]]);

        $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object']);

        $format = $this->sentBody()['response_format'];
        $this->assertSame('A person', $format['json_schema']['description']);
        $this->assertSame('Person', $format['json_schema']['name']);
    }

    public function test_strict_structured_mode_forbids_additional_properties_without_touching_other_keywords(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['v' => ['type' => 'string']]]],
                'type' => ['type' => 'string', 'enum' => ['object']],
            ],
            'required' => ['tags'],
        ];

        $this->provider(strict: true)->structured(new UserMessage('Who?'), 'Person', $schema);

        $sent = $this->sentBody()['response_format']['json_schema'];
        $this->assertTrue($sent['strict']);
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['v' => ['type' => 'string']], 'additionalProperties' => false]],
                'type' => ['type' => 'string', 'enum' => ['object']],
            ],
            'required' => ['tags'],
            'additionalProperties' => false,
        ], $sent['schema']);
    }

    public function test_structured_restores_the_parameters_after_success_and_failure(): void
    {
        $provider = new OpenAI('sk-test', 'gpt-test', ['temperature' => 1], httpClient: $this->recordingClient(
            new Response(200, body: self::ANSWER),
            new Response(500, body: '{"error":{"message":"boom"}}'),
            new Response(200, body: self::ANSWER),
        ));

        $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object']);
        try {
            $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object']);
            $this->fail('The failing request must surface.');
        } catch (HttpException) {
        }
        $provider->chat(new UserMessage('Hi'));

        $body = $this->sentBody(2);
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertSame(1, $body['temperature']);
    }
}
