<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Providers\AWS\MessageMapper as AWSMessageMapper;
use NeuronAI\Providers\Anthropic\MessageMapper as AnthropicMessageMapper;
use NeuronAI\Providers\Gemini\MessageMapper as GeminiMessageMapper;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\Mistral\MessageMapper as MistralMessageMapper;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;
use NeuronAI\Providers\OpenAI\Responses\MessageMapper as OpenAIResponsesMessageMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_is_list;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class MessageMapperReindexingTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $expectedBlocks
     */
    #[DataProvider('mapperProvider')]
    public function test_filtered_blocks_serialize_as_lists(
        MessageMapperInterface $mapper,
        AssistantMessage $message,
        string $blocksKey,
        array $expectedBlocks,
    ): void {
        $payload = json_decode(json_encode($mapper->map([$message]), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload[0][$blocksKey]);
        $this->assertTrue(array_is_list($payload[0][$blocksKey]));
        $this->assertSame($expectedBlocks, $payload[0][$blocksKey]);
    }

    /**
     * @return iterable<string, array{0: MessageMapperInterface, 1: AssistantMessage, 2: string, 3: array<int, array<string, mixed>>}>
     */
    public static function mapperProvider(): iterable
    {
        yield 'openai responses reasoning then text' => [
            new OpenAIResponsesMessageMapper(),
            new AssistantMessage([
                new ReasoningContent('Thinking'),
                new TextContent('Hello'),
            ]),
            'content',
            [
                ['type' => 'output_text', 'text' => 'Hello'],
            ],
        ];

        yield 'openai reasoning then text' => [
            new OpenAIMessageMapper(),
            new AssistantMessage([
                new ReasoningContent('Thinking'),
                new TextContent('Hello'),
            ]),
            'content',
            [
                ['type' => 'text', 'text' => 'Hello'],
            ],
        ];

        yield 'anthropic unsupported image id then text' => [
            new AnthropicMessageMapper(),
            new AssistantMessage([
                new ImageContent('file_123', SourceType::ID),
                new TextContent('Hello'),
            ]),
            'content',
            [
                ['type' => 'text', 'text' => 'Hello'],
            ],
        ];

        yield 'mistral unsupported base64 file then text' => [
            new MistralMessageMapper(),
            new AssistantMessage([
                new FileContent('ZmFrZQ==', SourceType::BASE64, 'application/pdf', 'test.pdf'),
                new TextContent('Hello'),
            ]),
            'content',
            [
                ['type' => 'text', 'text' => 'Hello'],
            ],
        ];

        yield 'gemini unsupported image id then text' => [
            new GeminiMessageMapper(),
            new AssistantMessage([
                new ImageContent('file_123', SourceType::ID, 'image/png'),
                new TextContent('Hello'),
            ]),
            'parts',
            [
                ['text' => 'Hello'],
            ],
        ];

        yield 'aws unsupported image then text' => [
            new AWSMessageMapper(),
            new AssistantMessage([
                new ImageContent('https://example.com/image.png', SourceType::URL),
                new TextContent('Hello'),
            ]),
            'content',
            [
                ['text' => 'Hello'],
            ],
        ];
    }
}
