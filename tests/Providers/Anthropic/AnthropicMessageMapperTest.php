<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Anthropic\MessageMapper;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class AnthropicMessageMapperTest extends TestCase
{
    public function test_file_id_sources_map_to_file_references(): void
    {
        $message = (new UserMessage('Compare'))
            ->addContent(new ImageContent('file_img', SourceType::ID))
            ->addContent(new FileContent('file_doc', SourceType::ID));

        $this->assertSame([
            ['type' => 'text', 'text' => 'Compare'],
            ['type' => 'image', 'source' => ['type' => 'file', 'file_id' => 'file_img']],
            ['type' => 'document', 'source' => ['type' => 'file', 'file_id' => 'file_doc']],
        ], (new MessageMapper())->map([$message])[0]['content']);
    }

    public function test_unsupported_blocks_are_dropped_and_the_list_is_reindexed(): void
    {
        $message = new UserMessage([
            new AudioContent('YXVkaW8=', SourceType::BASE64, 'audio/wav'),
            new TextContent('Transcribe'),
        ]);

        $content = (new MessageMapper())->map([$message])[0]['content'];

        $this->assertSame([['type' => 'text', 'text' => 'Transcribe']], $content);
        $this->assertSame('[{"type":"text","text":"Transcribe"}]', json_encode($content, JSON_THROW_ON_ERROR));
    }

    public function test_tool_calls_become_assistant_tool_use_blocks_after_the_text(): void
    {
        $message = new ToolCallMessage('Let me check', [
            new ToolCall('weather', 'toolu_1', ['city' => 'Rome']),
            new ToolCall('clock', 'toolu_2', []),
        ]);

        $this->assertSame(
            '[{"role":"assistant","content":[{"type":"text","text":"Let me check"},'
            .'{"type":"tool_use","id":"toolu_1","name":"weather","input":{"city":"Rome"}},'
            .'{"type":"tool_use","id":"toolu_2","name":"clock","input":{}}]}]',
            json_encode((new MessageMapper())->map([$message]), JSON_THROW_ON_ERROR),
        );
    }

    public function test_tool_results_are_sent_as_a_user_turn_answering_each_call_id(): void
    {
        $message = new ToolResultMessage([
            (new ToolCall('weather', 'toolu_1', ['city' => 'Rome']))->setResult('Sunny'),
            (new ToolCall('clock', 'toolu_2', []))->setResult('12:00'),
        ]);

        $this->assertSame(
            '[{"role":"user","content":['
            .'{"type":"tool_result","tool_use_id":"toolu_1","content":"Sunny"},'
            .'{"type":"tool_result","tool_use_id":"toolu_2","content":"12:00"}]}]',
            json_encode((new MessageMapper())->map([$message]), JSON_THROW_ON_ERROR),
        );
    }

    public function test_conversation_order_and_roles_are_preserved_one_to_one(): void
    {
        $messages = [
            new UserMessage('First'),
            new UserMessage('Second'),
            new AssistantMessage('Reply'),
            new AssistantMessage('More'),
        ];

        $mapped = (new MessageMapper())->map($messages);

        $this->assertSame(['user', 'user', 'assistant', 'assistant'], array_column($mapped, 'role'));
        $this->assertSame(['First', 'Second', 'Reply', 'More'], array_map(fn (array $item): string => $item['content'][0]['text'], $mapped));
    }

    public function test_system_messages_in_the_history_are_rejected(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Could not map message type '.SystemMessage::class);

        (new MessageMapper())->map([new SystemMessage('Injected instructions')]);
    }
}
