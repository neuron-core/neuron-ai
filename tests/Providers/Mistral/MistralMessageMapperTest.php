<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Mistral;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Mistral\MessageMapper;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class MistralMessageMapperTest extends TestCase
{
    /**
     * @return array<string, array{ContentBlockInterface, array<string, mixed>|null}>
     */
    public static function content_blocks(): array
    {
        return [
            'image url' => [new ImageContent('https://x.test/a.png', SourceType::URL, 'image/png'), ['type' => 'image_url', 'image_url' => ['url' => 'https://x.test/a.png']]],
            'image base64 becomes a data uri' => [new ImageContent('iVBORw0=', SourceType::BASE64, 'image/png'), ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0=']]],
            'document url with name' => [new FileContent('https://x.test/a.pdf', SourceType::URL, 'application/pdf', 'a.pdf'), ['type' => 'document_url', 'document_url' => 'https://x.test/a.pdf', 'document_name' => 'a.pdf']],
            'uploaded document id' => [new FileContent('file-123', SourceType::ID, 'application/pdf'), ['type' => 'file', 'file_id' => 'file-123']],
            'base64 document is unsupported' => [new FileContent('JVBERi0=', SourceType::BASE64, 'application/pdf'), null],
            'audio' => [new AudioContent('SUQz', SourceType::BASE64, 'audio/mpeg'), ['type' => 'input_audio', 'input_audio' => 'SUQz']],
            'video is unsupported' => [new VideoContent('https://x.test/v.mp4', SourceType::URL, 'video/mp4'), null],
        ];
    }

    /**
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('content_blocks')]
    public function test_content_blocks_are_mapped_to_mistral_chunks(ContentBlockInterface $block, ?array $expected): void
    {
        $content = (new MessageMapper())->map([new UserMessage([new TextContent('See'), $block])])[0]['content'];

        $text = ['type' => 'text', 'text' => 'See'];
        $this->assertSame($expected === null ? [$text] : [$text, $expected], $content);
    }

    public function test_tool_call_message_carries_ids_and_json_encoded_arguments(): void
    {
        $message = new ToolCallMessage(null, [
            ToolCall::make('lookup', 'call_a', ['q' => 'ünï "quoted"']),
            ToolCall::make('now', 'call_b', []),
        ]);

        $mapped = (new MessageMapper())->map([$message])[0];

        $this->assertSame('assistant', $mapped['role']->value);
        $this->assertArrayNotHasKey('content', $mapped);
        [$first, $second] = $mapped['tool_calls'];
        $this->assertSame(['id' => 'call_a', 'type' => 'function'], ['id' => $first['id'], 'type' => $first['type']]);
        $this->assertSame('lookup', $first['function']['name']);
        $this->assertSame(['q' => 'ünï "quoted"'], json_decode($first['function']['arguments'], true, flags: JSON_THROW_ON_ERROR));
        // Mistral requires an object, never "[]", for calls without arguments.
        $this->assertSame(['call_b', 'now', '{}'], [$second['id'], $second['function']['name'], $second['function']['arguments']]);
    }

    public function test_tool_call_message_content_is_sent_only_when_present(): void
    {
        $message = new ToolCallMessage('Checking', [ToolCall::make('lookup', 'call_a')]);

        $mapped = (new MessageMapper())->map([$message])[0];

        $this->assertSame([['type' => 'text', 'text' => 'Checking']], $mapped['content']);
    }

    public function test_each_tool_result_becomes_a_tool_message_bound_to_its_call_id(): void
    {
        $message = new ToolResultMessage([
            ToolCall::make('lookup', 'call_a')->setResult('first'),
            ToolCall::make('lookup', 'call_b')->setResult('second'),
        ]);

        $this->assertSame([
            ['role' => 'tool', 'tool_call_id' => 'call_a', 'content' => 'first'],
            ['role' => 'tool', 'tool_call_id' => 'call_b', 'content' => 'second'],
        ], json_decode(json_encode((new MessageMapper())->map([$message]), JSON_THROW_ON_ERROR), true));
    }

    public function test_mapper_does_not_leak_messages_between_calls(): void
    {
        $mapper = new MessageMapper();

        $mapper->map([new UserMessage('first conversation')]);
        $mapped = $mapper->map([new AssistantMessage('second conversation')]);

        $this->assertSame([['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'second conversation']]]], $mapped);
    }

    public function test_system_message_is_not_mappable(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Unknown message type '.SystemMessage::class);

        (new MessageMapper())->map([new SystemMessage('instructions')]);
    }
}
