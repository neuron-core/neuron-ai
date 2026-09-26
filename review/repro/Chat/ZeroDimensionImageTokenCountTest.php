<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function pack;

class ZeroDimensionImageTokenCountTest extends TestCase
{
    /** @return array<string, array{int, int}> */
    public static function zeroHeightImages(): array
    {
        return [
            'zero height' => [100, 0],
            'zero width and height' => [0, 0],
            'oversized width, zero height' => [5000, 0],
        ];
    }

    #[DataProvider('zeroHeightImages')]
    public function test_an_image_declaring_a_zero_height_is_counted_without_error(int $width, int $height): void
    {
        $image = new ImageContent(base64_encode($this->pngHeader($width, $height)), SourceType::BASE64, 'image/png');

        $this->assertGreaterThanOrEqual(1, (new TokenCounter())->count(new UserMessage([$image])));
    }

    public function test_a_user_upload_declaring_a_zero_height_does_not_break_the_conversation(): void
    {
        $store = new InMemoryMessageStore();
        $history = new ChatHistory($store, 'thread');

        $history->addMessage(new UserMessage([
            new TextContent('What is in this picture?'),
            new ImageContent(base64_encode($this->pngHeader(100, 0)), SourceType::BASE64, 'image/png'),
        ]));

        $this->assertCount(1, $store->loadActive('thread'));
    }

    /**
     * The IHDR header is all getimagesizefromstring() reads to size a PNG.
     */
    protected function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00" . pack('N', 0);
    }
}
