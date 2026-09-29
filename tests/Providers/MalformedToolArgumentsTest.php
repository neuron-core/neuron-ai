<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\ZAI\ZAI;
use NeuronAI\Tests\Providers\AWS\Stub\BedrockStreamClient;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_ends_with;

use const JSON_THROW_ON_ERROR;

/**
 * Tool arguments that are not a JSON object must never run a tool with empty
 * or wrong inputs, nor escape as a TypeError: every provider raises the same
 * ProviderException naming the tool.
 */
class MalformedToolArgumentsTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedArguments(): iterable
    {
        yield 'truncated object' => ['{"q":"par'];
        yield 'not json' => ['q=paris'];
        yield 'json string' => ['"paris"'];
        yield 'json number' => ['42'];
        yield 'json list' => ['["paris"]'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providersWithMalformedArguments(): iterable
    {
        foreach (['openai', 'openai compatible', 'openai responses', 'mistral', 'anthropic stream', 'bedrock stream'] as $provider) {
            foreach (self::malformedArguments() as $case => [$arguments]) {
                yield "{$provider}, {$case}" => [$provider, $arguments];
            }
        }
    }

    #[DataProvider('providersWithMalformedArguments')]
    public function test_malformed_arguments_raise_a_provider_exception_naming_the_tool(string $provider, string $arguments): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model sent invalid arguments for tool "lookup"');

        $this->toolCallMessage($provider, $arguments);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providers(): iterable
    {
        foreach (['openai', 'openai compatible', 'openai responses', 'mistral', 'anthropic stream', 'bedrock stream'] as $provider) {
            yield $provider => [$provider];
        }
    }

    #[DataProvider('providers')]
    public function test_empty_arguments_mean_a_call_without_inputs(string $provider): void
    {
        $message = $this->toolCallMessage($provider, '');

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame([], $message->getToolCalls()[0]->getInputs());
    }

    #[DataProvider('providers')]
    public function test_valid_arguments_reach_the_tool_call(string $provider): void
    {
        $message = $this->toolCallMessage($provider, '{"q":"paris","limit":0}');

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['q' => 'paris', 'limit' => 0], $message->getToolCalls()[0]->getInputs());
    }

    public function test_the_exception_carries_the_decoding_error(): void
    {
        $this->expectExceptionMessage('The model sent invalid arguments for tool "lookup": Syntax error');

        $this->toolCallMessage('openai', '{"q": }');
    }

    public function test_a_bedrock_tool_call_cut_off_by_max_tokens_is_discarded(): void
    {
        $provider = new BedrockRuntime(new BedrockStreamClient([
            ['contentBlockStart' => ['contentBlockIndex' => 0, 'start' => ['toolUse' => ['name' => 'lookup', 'toolUseId' => 'id-1']]]],
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['toolUse' => ['input' => '{"q":"par']]]],
            ['messageStop' => ['stopReason' => 'max_tokens']],
        ]), 'model');
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Where?')));

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
    }

    protected function toolCallMessage(string $provider, string $arguments): Message
    {
        $build = match ($provider) {
            'openai' => fn (): AIProviderInterface => new OpenAI('key', 'model', httpClient: $this->completion($arguments)),
            'openai compatible' => fn (): AIProviderInterface => new ZAI('key', 'glm-4.6', httpClient: $this->completion($arguments)),
            'mistral' => fn (): AIProviderInterface => new Mistral('key', 'mistral-large-latest', httpClient: $this->completion($arguments)),
            'openai responses' => fn (): AIProviderInterface => new OpenAIResponses('key', 'model', httpClient: $this->recordingClient(new Response(200, body: json_encode([
                'status' => 'completed',
                'output' => [['type' => 'function_call', 'name' => 'lookup', 'call_id' => 'call_1', 'arguments' => $arguments]],
            ], JSON_THROW_ON_ERROR)))),
            'anthropic stream' => fn (): AIProviderInterface => new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: self::sseBody([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'lookup', 'input' => []]],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $arguments]],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
            ])))),
            'bedrock stream' => fn (): AIProviderInterface => new BedrockRuntime(new BedrockStreamClient([
                ['contentBlockStart' => ['contentBlockIndex' => 0, 'start' => ['toolUse' => ['name' => 'lookup', 'toolUseId' => 'id-1']]]],
                ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['toolUse' => ['input' => $arguments]]]],
                ['contentBlockStop' => ['contentBlockIndex' => 0]],
                ['messageStop' => ['stopReason' => 'tool_use']],
            ]), 'model'),
            default => $this->fail("Unknown provider {$provider}"),
        };

        $instance = $build()->setTools([new ToolStub('lookup', 'Look it up')]);

        if (str_ends_with($provider, 'stream')) {
            return $this->consumeStream($instance->stream(new UserMessage('Where?')))[1];
        }

        return $instance->chat(new UserMessage('Where?'))->message();
    }

    protected function completion(string $arguments): GuzzleHttpClient
    {
        return $this->recordingClient(new Response(200, body: json_encode([
            'id' => 'cmpl-1',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => $arguments]]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], JSON_THROW_ON_ERROR)));
    }
}
