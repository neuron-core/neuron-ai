<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function array_column;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class OpenAIResponsesChatTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ANSWER = '{"status":"completed","output":[{"type":"message","content":[{"type":"output_text","text":"Answer"}]}]}';

    /**
     * @param array<string, mixed> $parameters
     */
    protected function provider(string $response = self::ANSWER, array $parameters = [], bool $strict = false): OpenAIResponses
    {
        $provider = new OpenAIResponses('sk-test', 'gpt-test', $parameters, $strict, $this->recordingClient(new Response(200, body: $response)));
        $provider->setTools([new ToolStub('weather'), new ToolStub('clock')]);

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_request_targets_the_responses_endpoint_with_input_and_instructions(): void
    {
        $provider = $this->provider(parameters: ['reasoning' => ['effort' => 'low']])->systemPrompt('Be concise');

        $provider->chat(new UserMessage('Hi'));

        $body = $this->sentBody();
        $this->assertSame(['POST https://api.openai.com/v1/responses'], $this->sentTargets());
        $this->assertSame('Bearer sk-test', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('gpt-test', $body['model']);
        $this->assertSame('Be concise', $body['instructions']);
        $this->assertSame([['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hi']]]], $body['input']);
        $this->assertSame(['effort' => 'low'], $body['reasoning']);
        $this->assertSame(['weather', 'clock'], array_column($body['tools'], 'name'));
        $this->assertArrayNotHasKey('stream', $body);
    }

    public function test_reasoning_summary_and_text_output_become_ordered_blocks(): void
    {
        $response = json_encode(['status' => 'completed', 'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Thinking']]],
            ['type' => 'reasoning', 'id' => 'rs_2', 'summary' => []],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Answer']]],
        ]], JSON_THROW_ON_ERROR);

        $message = $this->provider($response)->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $blocks = $message->getContentBlocks();
        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(ReasoningContent::class, $blocks[0]);
        $this->assertSame(['Thinking', 'rs_1'], [$blocks[0]->content, $blocks[0]->id]);
        $this->assertInstanceOf(TextContent::class, $blocks[1]);
        $this->assertSame('Answer', $message->getContent());
        $this->assertSame('completed', $message->stopReason());
    }

    public function test_function_calls_become_a_tool_call_message_keyed_by_call_id(): void
    {
        $response = json_encode(['status' => 'completed', 'output' => [
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'weather', 'arguments' => '{"city":"Rome"}'],
            ['type' => 'function_call', 'id' => 'fc_2', 'call_id' => 'call_2', 'name' => 'clock', 'arguments' => ''],
        ], 'usage' => ['input_tokens' => 4, 'output_tokens' => 2]], JSON_THROW_ON_ERROR);

        $message = $this->provider($response)->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $calls = $message->getToolCalls();
        $this->assertSame(['call_1', 'call_2'], [$calls[0]->getCallId(), $calls[1]->getCallId()]);
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame([], $calls[1]->getInputs());
        $this->assertSame(4, $message->getUsage()->inputTokens);
        $this->assertSame('completed', $message->stopReason());
    }

    public function test_function_call_for_an_unregistered_tool_is_rejected(): void
    {
        $response = '{"output":[{"type":"function_call","id":"fc_1","call_id":"call_1","name":"rm","arguments":"{}"}]}';

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('non-existing tool: rm');

        $this->provider($response)->chat(new UserMessage('Hi'));
    }

    public function test_incomplete_response_reports_its_status_as_stop_reason(): void
    {
        $response = '{"status":"incomplete","incomplete_details":{"reason":"max_output_tokens"},"output":[{"type":"message","content":[{"type":"output_text","text":"Parti"}]}]}';

        $message = $this->provider($response)->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('incomplete', $message->stopReason());
    }

    public function test_error_body_is_raised_as_a_provider_exception(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI API Error: Invalid prompt');

        $this->provider('{"error":{"message":"Invalid prompt","code":"invalid_prompt"}}')->chat(new UserMessage('Hi'));
    }

    public function test_error_body_without_message_is_reported_verbatim(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI API Error: {"code":"server_error"}');

        $this->provider('{"error":{"code":"server_error"}}')->chat(new UserMessage('Hi'));
    }

    public function test_response_without_output_is_rejected(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI API Error: No output - {"status":"failed"}');

        $this->provider('{"status":"failed"}')->chat(new UserMessage('Hi'));
    }

    public function test_structured_sends_a_named_json_schema_text_format(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $this->provider()->structured([new UserMessage('Who?')], 'App\\Dto\\Person', $schema);

        $this->assertSame(
            ['format' => ['type' => 'json_schema', 'strict' => false, 'name' => 'Person', 'schema' => $schema]],
            $this->sentBody()['text'],
        );
    }

    public function test_structured_prefixes_schema_names_that_do_not_start_with_a_letter(): void
    {
        $this->provider()->structured(new UserMessage('Who?'), 'App\\Dto\\_Draft', ['type' => 'object']);

        $this->assertSame('class__Draft', $this->sentBody()['text']['format']['name']);
    }

    public function test_strict_structured_mode_forbids_additional_properties_recursively(): void
    {
        $schema = ['type' => 'object', 'properties' => ['address' => ['type' => 'object', 'properties' => []]]];

        $this->provider(strict: true)->structured(new UserMessage('Who?'), (new class () {})::class, $schema);

        $format = $this->sentBody()['text']['format'];
        $this->assertTrue($format['strict']);
        $this->assertSame('anonymous', $format['name']);
        $this->assertFalse($format['schema']['additionalProperties']);
        $this->assertFalse($format['schema']['properties']['address']['additionalProperties']);
    }

    public function test_structured_restores_the_parameters_after_success_and_failure(): void
    {
        $provider = new OpenAIResponses('sk-test', 'gpt-test', ['text' => ['verbosity' => 'low']], httpClient: $this->recordingClient(
            new Response(200, body: self::ANSWER),
            new Response(500, body: '{"error":{"message":"boom"}}'),
            new Response(200, body: self::ANSWER),
        ));

        $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object']);
        $this->assertSame('low', $this->sentBody()['text']['verbosity']);
        try {
            $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object']);
            $this->fail('The failing request must surface.');
        } catch (HttpException) {
        }
        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['verbosity' => 'low'], $this->sentBody(2)['text']);
    }
}
