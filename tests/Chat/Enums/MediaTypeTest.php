<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Enums;

use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class MediaTypeTest extends TestCase
{
    public function test_enum_is_normalized_to_string(): void
    {
        $block = new ImageContent('base64data', SourceType::BASE64, MediaType::JPEG);

        $this->assertSame('image/jpeg', $block->mediaType);
        $this->assertSame('image/jpeg', $block->toArray()['media_type']);
    }

    public function test_custom_string_is_preserved(): void
    {
        $block = new ImageContent('base64data', SourceType::BASE64, 'image/x-custom');

        $this->assertSame('image/x-custom', $block->mediaType);
    }

    public function test_media_type_defaults_to_null(): void
    {
        $block = new ImageContent('https://example.com/image.png', SourceType::URL);

        $this->assertNull($block->mediaType);
        $this->assertArrayNotHasKey('media_type', $block->toArray());
    }

    public function test_all_blocks_accept_the_enum(): void
    {
        $file = new FileContent('base64data', SourceType::BASE64, MediaType::PDF, 'report.pdf');
        $audio = new AudioContent('base64data', SourceType::BASE64, MediaType::MP3);
        $video = new VideoContent('base64data', SourceType::BASE64, MediaType::MP4);

        $this->assertSame('application/pdf', $file->mediaType);
        $this->assertSame('report.pdf', $file->filename);
        $this->assertSame('audio/mpeg', $audio->mediaType);
        $this->assertSame('video/mp4', $video->mediaType);
    }

    public function test_tool_output_factories_accept_the_enum(): void
    {
        $output = ToolOutput::image('base64data', SourceType::BASE64, MediaType::WEBP);

        $block = $output->getBlocks()[0];
        $this->assertInstanceOf(ImageContent::class, $block);
        $this->assertSame('image/webp', $block->mediaType);
    }
}
