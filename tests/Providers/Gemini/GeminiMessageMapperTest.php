<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Gemini\MessageMapper;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class GeminiMessageMapperTest extends TestCase
{
    /**
     * Maps through JSON, as the payload travels to the API.
     *
     * @param array<int, \NeuronAI\Chat\Messages\Message> $messages
     * @return array<int, array<string, mixed>>
     */
    protected function wire(array $messages): array
    {
        return json_decode(json_encode((new MessageMapper())->map($messages), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_assistant_role_is_renamed_to_model_and_user_role_is_kept(): void
    {
        $this->assertSame([
            ['role' => 'user', 'parts' => [['text' => 'Hi']]],
            ['role' => 'model', 'parts' => [['text' => 'Hello']]],
        ], $this->wire([new UserMessage('Hi'), new AssistantMessage('Hello')]));
    }

    public function test_reasoning_is_sent_as_thought_part_with_its_signature(): void
    {
        $reasoning = new ReasoningContent('Considering options');
        $reasoning->addMetadata('thought_signature', 'sig-r');
        $text = new TextContent('Answer');
        $text->addMetadata('thought_signature', 'sig-t');

        $this->assertSame([[
            'role' => 'model',
            'parts' => [
                ['thought' => true, 'text' => 'Considering options', 'thought_signature' => 'sig-r'],
                ['text' => 'Answer', 'thought_signature' => 'sig-t'],
            ],
        ]], $this->wire([new AssistantMessage([$reasoning, $text])]));
    }

    /**
     * @return array<string, array{ContentBlockInterface, array<string, mixed>|null}>
     */
    public static function media_blocks(): array
    {
        return [
            'image url' => [new ImageContent('https://x.test/a.png', SourceType::URL, 'image/png'), ['file_data' => ['file_uri' => 'https://x.test/a.png', 'mime_type' => 'image/png']]],
            'image base64' => [new ImageContent('iVBORw0=', SourceType::BASE64, 'image/png'), ['inline_data' => ['data' => 'iVBORw0=', 'mime_type' => 'image/png']]],
            'file url' => [new FileContent('gs://bucket/a.pdf', SourceType::URL, 'application/pdf'), ['file_data' => ['file_uri' => 'gs://bucket/a.pdf', 'mime_type' => 'application/pdf']]],
            'file base64' => [new FileContent('JVBERi0=', SourceType::BASE64, 'application/pdf'), ['inline_data' => ['data' => 'JVBERi0=', 'mime_type' => 'application/pdf']]],
            'audio url' => [new AudioContent('https://x.test/a.mp3', SourceType::URL, 'audio/mpeg'), ['file_data' => ['file_uri' => 'https://x.test/a.mp3', 'mime_type' => 'audio/mpeg']]],
            'audio base64' => [new AudioContent('SUQz', SourceType::BASE64, 'audio/mpeg'), ['inline_data' => ['data' => 'SUQz', 'mime_type' => 'audio/mpeg']]],
            'video url' => [new VideoContent('https://youtu.be/x', SourceType::URL, 'video/mp4'), ['file_data' => ['file_uri' => 'https://youtu.be/x', 'mime_type' => 'video/mp4']]],
            'video base64' => [new VideoContent('AAAA', SourceType::BASE64, 'video/mp4'), ['inline_data' => ['data' => 'AAAA', 'mime_type' => 'video/mp4']]],
            'provider file id is not supported' => [new ImageContent('file-123', SourceType::ID, 'image/png'), null],
        ];
    }

    /**
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('media_blocks')]
    public function test_media_blocks_map_to_file_or_inline_data(ContentBlockInterface $block, ?array $expected): void
    {
        $parts = $this->wire([new UserMessage([new TextContent('Look'), $block])])[0]['parts'];

        $this->assertSame($expected === null ? [['text' => 'Look']] : [['text' => 'Look'], $expected], $parts);
    }

    public function test_tool_call_parts_follow_content_and_carry_the_signature_only_on_the_first_call(): void
    {
        $message = new ToolCallMessage('Let me look.', [
            ToolCall::make('search', 'call-1', ['query' => 'php']),
            ToolCall::make('now', 'call-2', []),
        ]);
        $message->addMetadata('thought_signature', 'sig-call');

        $mapped = (new MessageMapper())->map([$message]);

        $this->assertSame('{"role":"model","parts":[{"text":"Let me look."},'
            .'{"functionCall":{"name":"search","args":{"query":"php"}},"thought_signature":"sig-call"},'
            .'{"functionCall":{"name":"now","args":{}}}]}', json_encode($mapped[0], JSON_THROW_ON_ERROR));
    }

    public function test_tool_result_is_a_user_function_response_without_the_local_call_id(): void
    {
        $call = ToolCall::make('search', 'local-call-id', ['query' => 'php'])->setResult('found it');
        $message = new ToolResultMessage([$call]);

        $wire = $this->wire([$message]);

        $this->assertSame([[
            'role' => 'user',
            'parts' => [
                ['functionResponse' => ['name' => 'search', 'response' => ['name' => 'search', 'content' => 'found it']]],
            ],
        ]], $wire);
        $this->assertStringNotContainsString('local-call-id', json_encode($wire, JSON_THROW_ON_ERROR));
    }

    public function test_parallel_tool_results_keep_their_order(): void
    {
        $message = new ToolResultMessage([
            ToolCall::make('weather', 'a')->setResult('sunny'),
            ToolCall::make('weather', 'b')->setResult('rainy'),
        ]);

        $parts = $this->wire([$message])[0]['parts'];

        $this->assertSame(['sunny', 'rainy'], [
            $parts[0]['functionResponse']['response']['content'],
            $parts[1]['functionResponse']['response']['content'],
        ]);
    }

    public function test_tool_output_blocks_become_function_response_parts(): void
    {
        $message = new ToolResultMessage([
            ToolCall::make('chart', 'a')->setResult(new ToolOutput([new TextContent('Sales'), new ImageContent('iVBORw0=', SourceType::BASE64, 'image/png')])),
            // Nothing Gemini can map: an empty text part keeps the response valid.
            ToolCall::make('upload', 'b')->setResult(new ToolOutput([new ImageContent('file-1', SourceType::ID, 'image/png')])),
        ]);

        $parts = $this->wire([$message])[0]['parts'];

        $this->assertSame(
            ['parts' => [['text' => 'Sales'], ['inline_data' => ['data' => 'iVBORw0=', 'mime_type' => 'image/png']]]],
            $parts[0]['functionResponse']['response']['content'],
        );
        $this->assertSame(['parts' => [['text' => '']]], $parts[1]['functionResponse']['response']['content']);
    }

    public function test_system_message_is_not_mappable_as_content(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Could not map message type '.SystemMessage::class);

        (new MessageMapper())->map([new SystemMessage('instructions')]);
    }
}
