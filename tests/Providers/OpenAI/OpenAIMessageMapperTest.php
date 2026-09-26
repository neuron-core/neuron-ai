<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\MessageMapper;
use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class OpenAIMessageMapperTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function wire(array $messages): array
    {
        return json_decode(json_encode((new MessageMapper())->map($messages), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_file_blocks_map_by_source_and_url_files_are_dropped(): void
    {
        $message = (new UserMessage('Read these'))
            ->addContent(new FileContent('JVBERi0=', SourceType::BASE64, 'application/pdf', 'report.pdf'))
            ->addContent(new FileContent('file-abc', SourceType::ID))
            ->addContent(new FileContent('https://example.com/a.pdf', SourceType::URL));

        $this->assertSame([
            ['type' => 'text', 'text' => 'Read these'],
            ['type' => 'file', 'file' => ['filename' => 'report.pdf', 'file_data' => 'data:application/pdf;base64,JVBERi0=']],
            ['type' => 'file', 'file' => ['file_id' => 'file-abc']],
        ], $this->wire([$message])[0]['content']);
    }

    public function test_tool_call_message_serializes_arguments_as_a_json_object_string(): void
    {
        $message = new ToolCallMessage(null, [
            new ToolCall('weather', 'call_1', ['city' => 'Rome', 'days' => 2]),
            new ToolCall('clock', 'call_2', []),
        ]);

        $this->assertSame([[
            'role' => 'assistant',
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Rome","days":2}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'clock', 'arguments' => '{}']],
            ],
        ]], $this->wire([$message]));
    }

    public function test_tool_call_message_keeps_its_text_content(): void
    {
        $message = new ToolCallMessage('Checking', [new ToolCall('clock', 'call_2', [])]);

        $this->assertSame([['type' => 'text', 'text' => 'Checking']], $this->wire([$message])[0]['content']);
    }

    public function test_each_tool_result_becomes_its_own_tool_message_in_order(): void
    {
        $messages = [
            new ToolResultMessage([
                (new ToolCall('weather', 'call_1', []))->setResult('Sunny'),
                (new ToolCall('clock', 'call_2', []))->setResult('12:00'),
            ]),
            new UserMessage('Thanks'),
        ];

        $wire = $this->wire($messages);

        $this->assertSame([
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Sunny'],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '12:00'],
        ], [$wire[0], $wire[1]]);
        $this->assertSame('user', $wire[2]['role']);
        $this->assertCount(3, $wire);
    }

    public function test_a_reused_mapper_does_not_leak_messages_between_requests(): void
    {
        $mapper = new MessageMapper();

        $mapper->map([new UserMessage('First request'), new AssistantMessage('Reply')]);
        $second = $mapper->map([new UserMessage('Second request')]);

        $this->assertCount(1, $second);
        $this->assertSame('Second request', $second[0]['content'][0]['text']);
    }

    public function test_system_messages_in_the_history_are_rejected(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Unknown message type '.SystemMessage::class);

        (new MessageMapper())->map([new SystemMessage('Injected')]);
    }

    public function test_tool_parameters_are_merged_into_the_function_definition(): void
    {
        $tool = (new ToolStub('lookup', 'Look up'))->setParameters(['strict' => true]);

        $function = (new ToolMapper())->map([$tool])[0]['function'];

        $this->assertSame('lookup', $function['name']);
        $this->assertTrue($function['strict']);
    }

    public function test_tool_parameters_take_precedence_over_the_generated_function_definition(): void
    {
        $schema = ['type' => 'object', 'properties' => ['sku' => ['type' => 'string']], 'required' => ['sku']];
        $tool = (new ToolStub('lookup', 'Look up'))->setParameters(['parameters' => $schema]);

        $this->assertSame(
            [['type' => 'function', 'function' => ['name' => 'lookup', 'description' => 'Look up', 'parameters' => $schema]]],
            (new ToolMapper())->map([$tool]),
        );
    }

    public function test_provider_tools_are_rejected_by_chat_completions(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI completions API does not support built-in Tools');

        (new ToolMapper())->map([new ToolStub('lookup'), new ProviderTool('web_search')]);
    }
}
