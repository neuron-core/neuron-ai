<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

class MalformedToolArgumentsTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_anthropic_truncated_tool_arguments_raise_a_provider_exception(): void
    {
        $body = self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'counter', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"count": 1']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens'], 'usage' => ['output_tokens' => 5]],
        ]);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('counter')]);

        $this->expectException(ProviderException::class);

        $this->consumeStream($provider->stream(new UserMessage('Count')));
    }

    public function test_anthropic_empty_tool_arguments_decode_to_an_empty_input(): void
    {
        $body = self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'counter', 'input' => []]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
        ]);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('counter')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Count')));

        $this->assertSame([], $message->getToolCalls()[0]->getInputs());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'truncated object' => ['{"count": 1'],
            'json scalar' => ['"count"'],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_openai_invalid_tool_arguments_raise_a_provider_exception(string $arguments): void
    {
        $body = json_encode([
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'counter', 'arguments' => $arguments],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: (string) $body)));
        $provider->setTools([new ToolStub('counter')]);

        $this->expectException(ProviderException::class);

        $provider->chat(new UserMessage('Count'));
    }

    public function test_openai_empty_tool_arguments_decode_to_an_empty_input(): void
    {
        $body = json_encode([
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'counter', 'arguments' => ''],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: (string) $body)));
        $provider->setTools([new ToolStub('counter')]);

        $message = $provider->chat(new UserMessage('Count'))->message();

        $this->assertSame([], $message->getToolCalls()[0]->getInputs());
    }
}
