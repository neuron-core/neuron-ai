<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\Responses\MessageMapper;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class OpenAIResponsesMessageMapperTest extends TestCase
{
    /**
     * @param Message[] $messages
     * @return array<int, array<string, mixed>>
     */
    protected function wire(array $messages): array
    {
        return json_decode(json_encode((new MessageMapper())->map($messages), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_user_text_is_input_text_and_assistant_text_is_output_text(): void
    {
        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Question']]],
            ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Answer']]],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Generic user']]],
        ], $this->wire([
            new UserMessage('Question'),
            new AssistantMessage('Answer'),
            new Message(MessageRole::USER, 'Generic user'),
        ]));
    }

    public function test_images_and_files_map_for_every_source_type(): void
    {
        $message = new UserMessage([
            new ImageContent('https://example.com/a.png', SourceType::URL),
            new ImageContent('iVBORw0=', SourceType::BASE64, 'image/png'),
            new ImageContent('file-img', SourceType::ID),
            new FileContent('JVBERi0=', SourceType::BASE64, 'application/pdf', 'a.pdf'),
            new FileContent('https://example.com/a.pdf', SourceType::URL),
            new FileContent('file-doc', SourceType::ID),
        ]);

        $this->assertSame([
            ['type' => 'input_image', 'image_url' => 'https://example.com/a.png'],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,iVBORw0='],
            ['type' => 'input_image', 'file_id' => 'file-img'],
            ['type' => 'input_file', 'filename' => 'a.pdf', 'file_data' => 'data:application/pdf;base64,JVBERi0='],
            ['type' => 'input_file', 'file_url' => 'https://example.com/a.pdf'],
            ['type' => 'input_file', 'file_id' => 'file-doc'],
        ], $this->wire([$message])[0]['content']);
    }

    public function test_tool_calls_become_function_call_items_after_their_text(): void
    {
        $message = new ToolCallMessage('Checking', [
            new ToolCall('weather', 'call_1', ['city' => 'Rome']),
            new ToolCall('clock', 'call_2', []),
        ]);

        $this->assertSame([
            ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Checking']]],
            ['type' => 'function_call', 'name' => 'weather', 'arguments' => '{"city":"Rome"}', 'call_id' => 'call_1'],
            ['type' => 'function_call', 'name' => 'clock', 'arguments' => '{}', 'call_id' => 'call_2'],
        ], $this->wire([$message]));
    }

    public function test_tool_call_without_text_emits_only_function_call_items(): void
    {
        $wire = $this->wire([new ToolCallMessage(null, [new ToolCall('clock', 'call_2', [])])]);

        $this->assertSame([['type' => 'function_call', 'name' => 'clock', 'arguments' => '{}', 'call_id' => 'call_2']], $wire);
    }

    public function test_tool_results_become_function_call_output_items(): void
    {
        $message = new ToolResultMessage([
            (new ToolCall('weather', 'call_1', []))->setResult('Sunny'),
            (new ToolCall('clock', 'call_2', []))->setResult('12:00'),
        ]);

        $this->assertSame([
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => 'Sunny'],
            ['type' => 'function_call_output', 'call_id' => 'call_2', 'output' => '12:00'],
        ], $this->wire([$message]));
    }

    public function test_a_reused_mapper_does_not_leak_items_between_requests(): void
    {
        $mapper = new MessageMapper();

        $mapper->map([new UserMessage('First'), new AssistantMessage('Reply')]);

        $this->assertCount(1, $mapper->map([new UserMessage('Second')]));
    }

    public function test_system_messages_in_the_history_are_rejected(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Unknown message type '.SystemMessage::class);

        (new MessageMapper())->map([new SystemMessage('Injected')]);
    }
}
