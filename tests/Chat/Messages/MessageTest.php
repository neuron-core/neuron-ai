<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;
use ValueError;

use function array_map;
use function array_values;

class MessageTest extends TestCase
{
    public function test_each_message_gets_its_own_identity(): void
    {
        $first = new UserMessage('Hello');
        $second = new UserMessage('Hello');

        $this->assertMatchesRegularExpression('/^msg_[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->getId());
        $this->assertNotSame($first->getId(), $second->getId());
    }

    public function test_replacing_the_metadata_keeps_the_identity(): void
    {
        $message = new AssistantMessage('Hi');
        $id = $message->getId();

        $message->setMetadata(['provider_state' => 'opaque']);

        $this->assertSame($id, $message->getId());
        $this->assertSame('opaque', $message->getMetadata('provider_state'));
    }

    public function test_replacing_the_metadata_drops_the_previous_keys(): void
    {
        $message = (new UserMessage('Hi'))->addMetadata('stale', true);

        $message->setMetadata(['fresh' => true]);

        $this->assertNull($message->getMetadata('stale'));
        $this->assertTrue($message->getMetadata('fresh'));
    }

    public function test_a_streamed_message_takes_the_id_its_chunks_carried(): void
    {
        $message = new AssistantMessage('Hi');

        $message->setId('msg_streamed')->setMetadata(['provider_state' => 'opaque']);

        $this->assertSame('msg_streamed', $message->getId());
    }

    public function test_the_identity_is_serialized_as_a_field_and_not_as_metadata(): void
    {
        $message = (new UserMessage('Hi'))->setId('msg_1')->addMetadata('source', 'web');

        $this->assertSame([
            '__id' => 'msg_1',
            'role' => 'user',
            'content' => [['type' => ContentBlockType::TEXT, 'content' => 'Hi', 'meta' => []]],
            '__meta' => ['source' => 'web'],
        ], $message->jsonSerialize());
    }

    public function test_usage_is_serialized_only_when_present(): void
    {
        $message = new AssistantMessage('Hi');
        $this->assertArrayNotHasKey('usage', $message->jsonSerialize());

        $message->setUsage(new Usage(10, 5, 2, 1));
        $this->assertSame(
            ['input_tokens' => 10, 'output_tokens' => 5, 'cached_input_tokens' => 2, 'reasoning_tokens' => 1],
            $message->jsonSerialize()['usage']
        );
    }

    public function test_clone_owns_its_content_blocks(): void
    {
        $block = new SystemContent('Base instructions');
        $original = new SystemMessage($block);

        $copy = clone $original;
        [$copied] = $copy->getTextBlocks();
        $copied->content = 'Edited on the copy';
        $copy->cache();
        $copy->addContent(new SystemContent('Added on the copy'));

        $this->assertNotSame($block, $copied);
        $this->assertSame('Edited on the copy', $copied->content);
        $this->assertSame('Base instructions', $block->content);
        $this->assertFalse($block->isCached());
        $this->assertCount(1, $original->getContentBlocks());
    }

    public function test_clone_keeps_the_identity_and_metadata(): void
    {
        $original = (new UserMessage('Hi'))->addMetadata('source', 'web');

        $copy = clone $original;

        $this->assertSame($original->getId(), $copy->getId());
        $this->assertSame('web', $copy->getMetadata('source'));
    }

    public function test_a_string_becomes_a_single_text_block(): void
    {
        $blocks = (new UserMessage('Hello'))->getContentBlocks();

        $this->assertCount(1, $blocks);
        $this->assertSame(TextContent::class, $blocks[0]::class);
        $this->assertSame('Hello', $blocks[0]->getContent());
    }

    public function test_a_message_without_content_has_no_blocks_and_no_text(): void
    {
        $message = new UserMessage(null);

        $this->assertSame([], $message->getContentBlocks());
        $this->assertNull($message->getContent());
    }

    public function test_setting_a_string_replaces_the_previous_content(): void
    {
        $message = new UserMessage([new TextContent('first'), new ImageContent('https://example.com/a.png', SourceType::URL)]);

        $message->setContents('second');

        $this->assertCount(1, $message->getContentBlocks());
        $this->assertSame('second', $message->getContent());
    }

    public function test_add_content_appends_a_block(): void
    {
        $message = new UserMessage('first');

        $message->addContent(new TextContent('second'));

        $this->assertSame('first second', $message->getContent());
    }

    public function test_text_view_joins_the_text_blocks_and_skips_reasoning_and_media(): void
    {
        $message = new AssistantMessage([
            new ReasoningContent('thinking'),
            new TextContent('Here'),
            new ImageContent('https://example.com/a.png', SourceType::URL),
            new TextContent('you go'),
        ]);

        $this->assertSame('Here you go', $message->getContent());
        $this->assertSame(['Here', 'you go'], array_values(array_map(
            fn (TextContent $block): string => $block->content,
            $message->getTextBlocks()
        )));
    }

    public function test_a_message_with_only_reasoning_has_no_text(): void
    {
        $this->assertNull((new AssistantMessage([new ReasoningContent('thinking')]))->getContent());
    }

    public function test_typed_accessors_return_the_first_block_of_their_type(): void
    {
        $reasoning = new ReasoningContent('first thought', 'rs_1');
        $image = new ImageContent('https://example.com/a.png', SourceType::URL);
        $audio = new AudioContent('https://example.com/a.mp3', SourceType::URL);
        $message = new AssistantMessage([
            new TextContent('text'),
            $reasoning,
            $image,
            $audio,
            new ReasoningContent('second thought'),
            new ImageContent('https://example.com/b.png', SourceType::URL),
            new AudioContent('https://example.com/b.mp3', SourceType::URL),
        ]);

        $this->assertSame($reasoning, $message->getReasoning());
        $this->assertSame($image, $message->getImage());
        $this->assertSame($audio, $message->getAudio());
    }

    public function test_typed_accessors_return_null_when_the_type_is_absent(): void
    {
        $message = new UserMessage([new TextContent('text'), new FileContent('https://example.com/a.pdf', SourceType::URL)]);

        $this->assertNull($message->getReasoning());
        $this->assertNull($message->getImage());
        $this->assertNull($message->getAudio());
    }

    public function test_the_role_can_be_set_from_the_enum_or_its_value(): void
    {
        $message = new Message(MessageRole::USER, 'Hi');

        $this->assertSame('model', $message->setRole('model')->getRole());
        $this->assertSame('developer', $message->setRole(MessageRole::DEVELOPER)->getRole());
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $message = new Message(MessageRole::USER, 'Hi');

        $this->expectException(ValueError::class);

        $message->setRole('root');
    }

    public function test_the_stop_reason_is_kept_in_the_metadata(): void
    {
        $message = new AssistantMessage('Hi');
        $this->assertNull($message->stopReason());

        $message->setStopReason('end_turn');

        $this->assertSame('end_turn', $message->stopReason());
        $this->assertSame('end_turn', $message->getMetadata('stop_reason'));
    }

    public function test_the_assistant_role_can_be_overridden_at_construction(): void
    {
        $this->assertSame('model', (new AssistantMessage('Hi', MessageRole::MODEL))->getRole());
    }

    public function test_make_builds_the_message_with_its_role(): void
    {
        $message = UserMessage::make('Hello');

        $this->assertInstanceOf(UserMessage::class, $message);
        $this->assertSame('user', $message->getRole());
        $this->assertSame('Hello', $message->getContent());
    }
}
