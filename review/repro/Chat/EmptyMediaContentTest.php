<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function glob;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

class EmptyMediaContentTest extends TestCase
{
    /**
     * @return array<string, array{ContentBlockInterface}>
     */
    public static function mediaBlocksWithFalsyContent(): array
    {
        return [
            'empty file' => [new FileContent('', SourceType::BASE64, 'text/plain', 'empty.txt')],
            'zero file' => [new FileContent('0', SourceType::BASE64, 'text/plain', 'zero.txt')],
            'empty image' => [new ImageContent('', SourceType::BASE64, 'image/png')],
            'empty audio' => [new AudioContent('', SourceType::BASE64, 'audio/wav')],
            'empty video' => [new VideoContent('', SourceType::BASE64, 'video/mp4')],
        ];
    }

    #[DataProvider('mediaBlocksWithFalsyContent')]
    public function test_media_block_with_falsy_content_survives_the_round_trip(ContentBlockInterface $block): void
    {
        $message = new UserMessage([$block]);

        $this->assertArrayHasKey('content', $block->toArray());

        $restored = (new MessageDeserializer())->deserialize(json_decode(json_encode($message), true));
        $this->assertSame($block->getContent(), $restored->getContentBlocks()[0]->getContent());
    }

    public function test_thread_with_an_empty_attachment_can_be_loaded(): void
    {
        $dir = sys_get_temp_dir() . '/neuron-empty-media-' . uniqid();
        mkdir($dir);
        try {
            $store = new FileMessageStore($dir);
            $store->append('t1', new UserMessage('hello'));
            $store->append('t1', new UserMessage([new FileContent('', SourceType::BASE64, 'text/plain', 'empty.txt')]));
            $store->append('t1', new UserMessage('after'));

            $this->assertCount(3, $store->loadActive('t1'));
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }
}
