<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class ContentBlockTest extends TestCase
{
    /**
     * The payload shape stores and providers depend on.
     *
     * @return array<string, array{ContentBlockInterface, array<string, mixed>}>
     */
    public static function payloads(): array
    {
        return [
            'text' => [
                new TextContent('Hello'),
                ['type' => ContentBlockType::TEXT, 'content' => 'Hello', 'meta' => []],
            ],
            'reasoning' => [
                new ReasoningContent('thinking', 'rs_1'),
                ['type' => ContentBlockType::REASONING, 'content' => 'thinking', 'meta' => [], 'id' => 'rs_1'],
            ],
            'reasoning without id' => [
                new ReasoningContent('thinking'),
                ['type' => ContentBlockType::REASONING, 'content' => 'thinking', 'meta' => [], 'id' => null],
            ],
            'system' => [
                new SystemContent('Be concise.'),
                ['type' => ContentBlockType::SYSTEM, 'content' => 'Be concise.', 'meta' => []],
            ],
            'image' => [
                new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
                ['type' => ContentBlockType::IMAGE, 'content' => 'aGVsbG8=', 'source_type' => SourceType::BASE64, 'media_type' => 'image/png'],
            ],
            'file' => [
                new FileContent('file_123', SourceType::ID, 'application/pdf', 'report.pdf'),
                ['type' => ContentBlockType::FILE, 'content' => 'file_123', 'source_type' => SourceType::ID, 'media_type' => 'application/pdf', 'filename' => 'report.pdf'],
            ],
            'audio' => [
                new AudioContent('https://example.com/a.mp3', SourceType::URL, 'audio/mpeg'),
                ['type' => ContentBlockType::AUDIO, 'content' => 'https://example.com/a.mp3', 'source_type' => SourceType::URL, 'media_type' => 'audio/mpeg'],
            ],
            'video' => [
                new VideoContent('https://example.com/a.mp4', SourceType::URL, 'video/mp4'),
                ['type' => ContentBlockType::VIDEO, 'content' => 'https://example.com/a.mp4', 'source_type' => SourceType::URL, 'media_type' => 'video/mp4'],
            ],
            'file without optional fields' => [
                new FileContent('https://example.com/a.pdf', SourceType::URL),
                ['type' => ContentBlockType::FILE, 'content' => 'https://example.com/a.pdf', 'source_type' => SourceType::URL],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('payloads')]
    public function test_to_array_exposes_the_block_payload(ContentBlockInterface $block, array $expected): void
    {
        $this->assertSame($expected, $block->toArray());
        $this->assertSame($expected, $block->jsonSerialize());
        $this->assertSame($expected['type'], $block->getType());
    }

    public function test_the_type_serializes_as_its_string_value(): void
    {
        $this->assertSame(
            '{"type":"image","content":"x","source_type":"url"}',
            json_encode(new ImageContent('x', SourceType::URL), JSON_THROW_ON_ERROR)
        );
    }

    public function test_block_metadata_is_part_of_the_payload(): void
    {
        $text = (new TextContent('Hello'))->addMetadata('cache_control', ['type' => 'ephemeral']);
        $image = (new ImageContent('x', SourceType::URL))->addMetadata('detail', 'high');

        $this->assertSame(['cache_control' => ['type' => 'ephemeral']], $text->toArray()['meta']);
        $this->assertSame(['detail' => 'high'], $image->toArray()['meta']);
    }

    public function test_streamed_deltas_accumulate_in_order(): void
    {
        $block = new TextContent('Hel');

        $block->accumulateContent('lo, ');
        $block->accumulateContent('wörld 👋');

        $this->assertSame('Hello, wörld 👋', $block->getContent());
    }

    public function test_system_content_is_not_cached_until_asked(): void
    {
        $block = new SystemContent('Be concise.');
        $this->assertFalse($block->isCached());

        $this->assertSame($block, $block->cache());
        $this->assertTrue($block->isCached());
        $this->assertSame('Be concise.', (string) $block);
    }

    public function test_reasoning_is_a_text_block_of_its_own_type(): void
    {
        $block = new ReasoningContent('thinking', 'rs_1');

        $this->assertInstanceOf(TextContent::class, $block);
        $this->assertSame(ContentBlockType::REASONING, $block->getType());
        $this->assertSame('rs_1', $block->id);
    }
}
