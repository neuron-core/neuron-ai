<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\AWS\ToolMapper;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

use function json_encode;

use const PHP_EOL;

/**
 * Drives a real BedrockRuntimeClient whose transport is replaced by the SDK's
 * MockHandler: every payload goes through the SDK's own input validation
 * against the Converse API model, exactly as in production.
 */
class BedrockPayloadTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected array $commands = [];

    protected MockHandler $transport;

    protected function setUp(): void
    {
        $this->transport = new MockHandler();
    }

    protected function client(): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'AKIDEXAMPLE', 'secret' => 'secret'],
            'handler' => $this->transport,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function answer(array $result): void
    {
        $this->transport->append(function (CommandInterface $command, RequestInterface $request) use ($result): Result {
            $this->commands[] = $command->toArray();
            return new Result($result);
        });
    }

    protected function answerText(string $text = 'ok'): void
    {
        $this->answer([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => $text]]]],
            'stopReason' => 'end_turn',
            'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
        ]);
    }

    public function test_full_conversation_payload_passes_sdk_validation(): void
    {
        $this->answerText();
        $tool = (new ToolStub('lookup', 'Look it up'))
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true));
        $provider = (new BedrockRuntime($this->client(), 'anthropic.claude-v2', ['maxTokens' => 256]))
            ->setTools([$tool])
            ->systemPrompt('Be precise');

        $provider->chat(
            new UserMessage([
                new TextContent('Compare'),
                new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
                new FileContent('s3://bucket/report.pdf', SourceType::ID, 'application/pdf', 'report.pdf'),
            ]),
            new ToolCallMessage('Searching', [ToolCall::make('lookup', 'call-1', ['query' => 'php'])]),
            new ToolResultMessage([ToolCall::make('lookup', 'call-1', ['query' => 'php'])->setResult('found')]),
        );

        $command = $this->commands[0];
        $this->assertSame('anthropic.claude-v2', $command['modelId']);
        $this->assertSame([['text' => 'Be precise']], $command['system']);
        $this->assertSame(['maxTokens' => 256], $command['inferenceConfig']);
        $this->assertSame([
            [
                'role' => 'user',
                'content' => [
                    ['text' => 'Compare'],
                    ['image' => ['format' => 'png', 'source' => ['bytes' => 'hello']]],
                    ['document' => ['format' => 'pdf', 'name' => 'report-pdf', 'source' => ['s3Location' => ['uri' => 's3://bucket/report.pdf']]]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => [
                    ['text' => 'Searching'],
                    ['toolUse' => ['name' => 'lookup', 'input' => ['query' => 'php'], 'toolUseId' => 'call-1']],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['toolResult' => ['content' => [['json' => ['result' => 'found']]], 'toolUseId' => 'call-1']],
                ],
            ],
        ], $command['messages']);
        $this->assertSame('lookup', $command['toolConfig']['tools'][0]['toolSpec']['name']);
    }

    public function test_optional_sections_are_omitted_when_not_configured(): void
    {
        $this->answerText();
        $provider = (new BedrockRuntime($this->client(), 'model'))->systemPrompt('sys');

        $provider->chat(new UserMessage('Hi'));

        $this->assertArrayNotHasKey('inferenceConfig', $this->commands[0]);
        $this->assertArrayNotHasKey('toolConfig', $this->commands[0]);
    }

    public function test_tool_failures_are_flagged_as_error_results(): void
    {
        $this->answerText();
        $provider = (new BedrockRuntime($this->client(), 'model'))
            ->setTools([new ToolStub('lookup', 'Look it up')])
            ->systemPrompt('sys');

        $provider->chat(
            new UserMessage('Find'),
            new ToolCallMessage(null, [ToolCall::make('lookup', 'call-1'), ToolCall::make('lookup', 'call-2')]),
            new ToolResultMessage([
                ToolCall::make('lookup', 'call-1')->setResult(ToolOutput::error('Service unavailable')),
                ToolCall::make('lookup', 'call-2')->setResult(ToolOutput::text('found')),
            ]),
        );

        $this->assertSame([
            ['toolResult' => ['content' => [['text' => 'Service unavailable']], 'toolUseId' => 'call-1', 'status' => 'error']],
            ['toolResult' => ['content' => [['text' => 'found']], 'toolUseId' => 'call-2']],
        ], $this->commands[0]['messages'][2]['content']);
    }

    public function test_tool_parameters_extend_the_tool_spec(): void
    {
        $this->answerText();
        $provider = (new BedrockRuntime($this->client(), 'model'))
            ->setTools([(new ToolStub('lookup', 'Look it up'))->setParameters(['description' => 'Overridden description'])])
            ->systemPrompt('sys');

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame('Overridden description', $this->commands[0]['toolConfig']['tools'][0]['toolSpec']['description']);
    }

    public function test_provider_tools_are_rejected(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Bedrock Runtime does not support Provider Tools');

        (new ToolMapper())->map([new ToolStub('lookup', 'Look it up'), new ProviderTool('web_search')]);
    }

    public function test_response_usage_stop_reason_and_array_tool_input_are_mapped(): void
    {
        $this->answer([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['text' => 'Let me search.'],
                ['toolUse' => ['toolUseId' => 'tooluse_1', 'name' => 'lookup', 'input' => ['query' => 'neuron']]],
            ]]],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 20, 'outputTokens' => 7, 'cacheReadInputTokens' => 15],
        ]);
        $provider = (new BedrockRuntime($this->client(), 'model'))
            ->setTools([new ToolStub('lookup', 'Look it up')])
            ->systemPrompt('sys');

        $message = $provider->chat(new UserMessage('Find neuron'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Let me search.', $message->getContent());
        $this->assertSame('tool_use', $message->stopReason());
        $this->assertSame([20, 7, 15], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens, $message->getUsage()->cachedInputTokens]);
        [$call] = $message->getToolCalls();
        $this->assertSame(['lookup', 'tooluse_1', ['query' => 'neuron'], 'Look it up'], [$call->getName(), $call->getCallId(), $call->getInputs(), $call->getDescription()]);
        $this->assertSame([1], $message->getMetadata('aws_tool_positions'));
    }

    public function test_response_without_usage_has_zero_usage(): void
    {
        $this->answer(['output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'hi']]]], 'stopReason' => 'end_turn']);
        $provider = (new BedrockRuntime($this->client(), 'model'))->systemPrompt('sys');

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame([0, 0], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_tool_use_for_an_unregistered_tool_is_rejected(): void
    {
        $this->answer([
            'output' => ['message' => ['role' => 'assistant', 'content' => [
                ['toolUse' => ['toolUseId' => 'tooluse_1', 'name' => 'delete_everything', 'input' => []]],
            ]]],
            'stopReason' => 'tool_use',
        ]);
        $provider = (new BedrockRuntime($this->client(), 'model'))
            ->setTools([new ToolStub('lookup', 'Look it up')])
            ->systemPrompt('sys');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: delete_everything.');

        $provider->chat(new UserMessage('Hi'));
    }

    public function test_structured_output_appends_the_schema_to_the_system_prompt_only_for_that_call(): void
    {
        $this->answerText('{"name":"Ada"}');
        $this->answerText();
        $provider = (new BedrockRuntime($this->client(), 'model'))->systemPrompt('Base');
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $structured = $provider->structured(new UserMessage('Who?'), 'Person', $schema)->message();
        $provider->chat(new UserMessage('Plain'));

        $this->assertSame('{"name":"Ada"}', $structured->getContent());
        $this->assertSame(
            'Base'.PHP_EOL.'# OUTPUT CONSTRAINTS'.PHP_EOL.'Your response should be a JSON string following this schema: '.PHP_EOL.json_encode($schema),
            $this->commands[0]['system'][0]['text'],
        );
        $this->assertSame([['text' => 'Base']], $this->commands[1]['system']);
    }

    public function test_structured_output_restores_the_system_prompt_when_the_call_fails(): void
    {
        $this->transport->appendException(new AwsException('throttled', $this->createMock(CommandInterface::class)));
        $this->answerText();
        $provider = (new BedrockRuntime($this->client(), 'model'))->systemPrompt('Base');

        try {
            $provider->structured([new UserMessage('Who?')], 'Person', ['type' => 'object']);
            $this->fail('The AWS error must propagate.');
        } catch (AwsException $exception) {
            $this->assertSame('throttled', $exception->getMessage());
        }
        $provider->chat(new UserMessage('Plain'));

        $this->assertSame([['text' => 'Base']], $this->commands[0]['system']);
    }
}
