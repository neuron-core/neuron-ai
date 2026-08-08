<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat;

use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use PHPUnit\Framework\TestCase;

class ContentBlockMediaTypeTest extends TestCase
{
    public function test_image_content_accepts_media_type_enum(): void
    {
        $image = new ImageContent('base64data', SourceType::BASE64, MediaType::JPEG);

        $this->assertSame('image/jpeg', $image->mediaType);
        $this->assertSame('image/jpeg', $image->toArray()['media_type']);
    }

    public function test_audio_content_accepts_media_type_enum(): void
    {
        $audio = new AudioContent('base64data', SourceType::BASE64, MediaType::MP3);

        $this->assertSame('audio/mpeg', $audio->mediaType);
        $this->assertSame('audio/mpeg', $audio->toArray()['media_type']);
    }

    public function test_video_content_accepts_media_type_enum(): void
    {
        $video = new VideoContent('https://example.com/video.mp4', SourceType::URL, MediaType::MP4);

        $this->assertSame('video/mp4', $video->mediaType);
        $this->assertSame('video/mp4', $video->toArray()['media_type']);
    }

    public function test_file_content_accepts_media_type_enum(): void
    {
        $file = new FileContent('base64data', SourceType::BASE64, MediaType::PDF, 'document.pdf');

        $this->assertSame('application/pdf', $file->mediaType);
        $this->assertSame('application/pdf', $file->toArray()['media_type']);
    }

    public function test_content_blocks_still_accept_raw_mime_string(): void
    {
        $image = new ImageContent('base64data', SourceType::BASE64, 'image/x-custom');

        $this->assertSame('image/x-custom', $image->mediaType);
    }

    public function test_media_type_defaults_to_null(): void
    {
        $image = new ImageContent('https://example.com/image.jpg', SourceType::URL);

        $this->assertNull($image->mediaType);
        $this->assertArrayNotHasKey('media_type', $image->toArray());
    }
}
