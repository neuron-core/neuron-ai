<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class ContentBlockDeserializationTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = \sys_get_temp_dir() . '/neuron_test_' . \uniqid();
        \mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up test directory
        if (\is_dir($this->testDir)) {
            $files = \glob($this->testDir . '/*');
            foreach ($files as $file) {
                if (\is_file($file)) {
                    \unlink($file);
                }
            }
            \rmdir($this->testDir);
        }
    }

    public function test_legacy_string_content_migrates_to_content_blocks(): void
    {
        $key = 'test_migration';
        $filePath = $this->testDir . '/neuron_' . $key . '.chat';

        // Simulate legacy format: content as string
        $legacyData = [
            [
                'role' => 'user',
                'content' => 'Hello, this is a legacy message!',
            ],
            [
                'role' => 'assistant',
                'content' => 'This is a legacy response.',
            ],
        ];

        \file_put_contents($filePath, \json_encode($legacyData));

        // Load with FileChatHistory - should automatically migrate
        $history = new FileChatHistory($this->testDir, $key);

        $messages = $history->getMessages();
        $this->assertCount(2, $messages);

        // Verify messages were loaded
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);

        // Verify content was converted to TextContent blocks
        $userContentBlocks = $messages[0]->getContentBlocks();
        $this->assertCount(1, $userContentBlocks);
        $this->assertInstanceOf(TextContent::class, $userContentBlocks[0]);
        $this->assertEquals('Hello, this is a legacy message!', $userContentBlocks[0]->text);

        $assistantContentBlocks = $messages[1]->getContentBlocks();
        $this->assertCount(1, $assistantContentBlocks);
        $this->assertInstanceOf(TextContent::class, $assistantContentBlocks[0]);
        $this->assertEquals('This is a legacy response.', $assistantContentBlocks[0]->text);

        // Now save it back - should be in new format
        $history->addMessage(new UserMessage('New message'));

        // Reload and verify it's still in content block format
        $history2 = new FileChatHistory($this->testDir, $key);
        $messages2 = $history2->getMessages();

        $this->assertCount(2, $messages2);

        // Verify original messages still have content blocks
        $this->assertInstanceOf(TextContent::class, $messages2[0]->getContentBlocks()[0]);
        $this->assertEquals('Hello, this is a legacy message!', $messages2[0]->getContentBlocks()[0]->text);
    }

    public function test_new_content_block_format_deserializes_correctly(): void
    {
        $key = 'test_blocks';
        $filePath = $this->testDir . '/neuron_' . $key . '.chat';

        // New format: content as array of content blocks
        $newData = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Analyze this image:',
                    ],
                    [
                        'type' => 'image',
                        'source' => 'https://example.com/image.jpg',
                        'source_type' => 'url',
                        'media_type' => 'image/jpeg',
                    ],
                ],
            ],
        ];

        \file_put_contents($filePath, \json_encode($newData));

        $history = new FileChatHistory($this->testDir, $key);
        $messages = $history->getMessages();

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);

        $contentBlocks = $messages[0]->getContentBlocks();
        $this->assertCount(2, $contentBlocks);

        // Verify text block
        $this->assertInstanceOf(TextContent::class, $contentBlocks[0]);
        $this->assertEquals('Analyze this image:', $contentBlocks[0]->text);

        // Verify image block
        $this->assertInstanceOf(ImageContent::class, $contentBlocks[1]);
        $this->assertEquals('https://example.com/image.jpg', $contentBlocks[1]->source);
        $this->assertEquals(SourceType::URL, $contentBlocks[1]->sourceType);
        $this->assertEquals('image/jpeg', $contentBlocks[1]->mediaType);
    }

    public function test_all_content_block_types_deserialize_correctly(): void
    {
        $key = 'test_all_types';
        $filePath = $this->testDir . '/neuron_' . $key . '.chat';

        $data = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Text content',
                    ],
                    [
                        'type' => 'image',
                        'source' => 'base64data',
                        'source_type' => 'base64',
                        'media_type' => 'image/png',
                    ],
                    [
                        'type' => 'file',
                        'source' => 'https://example.com/doc.pdf',
                        'source_type' => 'url',
                        'media_type' => 'application/pdf',
                        'filename' => 'document.pdf',
                    ],
                    [
                        'type' => 'audio',
                        'source' => 'https://example.com/audio.mp3',
                        'source_type' => 'url',
                        'media_type' => 'audio/mpeg',
                    ],
                    [
                        'type' => 'video',
                        'source' => 'https://example.com/video.mp4',
                        'source_type' => 'url',
                        'media_type' => 'video/mp4',
                    ],
                ],
            ],
        ];

        \file_put_contents($filePath, \json_encode($data));

        $history = new FileChatHistory($this->testDir, $key);
        $messages = $history->getMessages();

        $this->assertCount(1, $messages);

        $contentBlocks = $messages[0]->getContentBlocks();
        $this->assertCount(5, $contentBlocks);

        // Verify all block types
        $this->assertInstanceOf(TextContent::class, $contentBlocks[0]);
        $this->assertEquals('Text content', $contentBlocks[0]->text);

        $this->assertInstanceOf(ImageContent::class, $contentBlocks[1]);
        $this->assertEquals('base64data', $contentBlocks[1]->source);
        $this->assertEquals(SourceType::BASE64, $contentBlocks[1]->sourceType);

        $this->assertInstanceOf(FileContent::class, $contentBlocks[2]);
        $this->assertEquals('https://example.com/doc.pdf', $contentBlocks[2]->source);
        $this->assertEquals('document.pdf', $contentBlocks[2]->filename);

        $this->assertInstanceOf(AudioContent::class, $contentBlocks[3]);
        $this->assertEquals('https://example.com/audio.mp3', $contentBlocks[3]->source);

        $this->assertInstanceOf(VideoContent::class, $contentBlocks[4]);
        $this->assertEquals('https://example.com/video.mp4', $contentBlocks[4]->source);
    }

    public function test_mixed_messages_with_content_blocks(): void
    {
        $key = 'test_mixed';
        $filePath = $this->testDir . '/neuron_' . $key . '.chat';

        // Mix of new and legacy formats
        $mixedData = [
            [
                'role' => 'user',
                'content' => 'Legacy string message',
            ],
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Modern content block message',
                    ],
                ],
            ],
        ];

        \file_put_contents($filePath, \json_encode($mixedData));

        $history = new FileChatHistory($this->testDir, $key);
        $messages = $history->getMessages();

        $this->assertCount(2, $messages);

        // Both should have TextContent blocks
        $userBlocks = $messages[0]->getContentBlocks();
        $this->assertCount(1, $userBlocks);
        $this->assertInstanceOf(TextContent::class, $userBlocks[0]);
        $this->assertEquals('Legacy string message', $userBlocks[0]->text);

        $assistantBlocks = $messages[1]->getContentBlocks();
        $this->assertCount(1, $assistantBlocks);
        $this->assertInstanceOf(TextContent::class, $assistantBlocks[0]);
        $this->assertEquals('Modern content block message', $assistantBlocks[0]->text);
    }
}
