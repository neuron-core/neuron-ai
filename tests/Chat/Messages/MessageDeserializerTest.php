<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages;

use NeuronAI\Chat\Enums\MediaType;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class MessageDeserializerTest extends TestCase
{
    /**
     * @return array<string, array{Message}>
     */
    public static function messages(): array
    {
        $call = new ToolCall('lookup', 'call-1', ['query' => 'neuron']);
        $settled = (new ToolCall('lookup', 'call-1', ['query' => 'neuron']))->setResult('found');

        return [
            'user' => [new UserMessage('Hello')],
            'assistant' => [new AssistantMessage('Hi there')],
            'assistant with usage' => [(new AssistantMessage('Hi there'))->setUsage(new Usage(100, 20, 80, 5))],
            'tool call' => [new ToolCallMessage('Let me check', [$call])],
            'tool result' => [new ToolResultMessage([$settled])],
            'every content block type' => [new UserMessage([
                (new TextContent('Look at these'))->addMetadata('cache_control', ['type' => 'ephemeral']),
                new ImageContent('aGVsbG8=', SourceType::BASE64, MediaType::PNG),
                new FileContent('file_123', SourceType::ID, 'application/x-custom', 'report.bin'),
                new AudioContent('https://example.com/a.mp3', SourceType::URL, MediaType::MP3),
                new VideoContent('https://example.com/a.mp4', SourceType::URL),
            ])],
            'reasoning and text' => [new AssistantMessage([new ReasoningContent('thinking', 'rs_1'), new TextContent('Answer')])],
            'multibyte text' => [new UserMessage("Ciao 👋 — 日本語\n\t\"quoted\" \\ back")],
            'system' => [new SystemMessage([new TextContent('Be concise.'), new TextContent('Answer in English.')])],
            'model role' => [new Message(MessageRole::MODEL, 'Hi')],
            'tool call with multimodal and error results' => [new ToolResultMessage([
                (new ToolCall('chart', 'call-1', ['symbol' => 'AAPL']))->setResult(ToolOutput::image('aGVsbG8=', SourceType::BASE64, MediaType::PNG)),
                (new ToolCall('mail', 'call-2', ['to' => 'ops@example.com'], deferred: true))->setResult(ToolOutput::error('SMTP refused')),
            ])],
        ];
    }

    #[DataProvider('messages')]
    public function test_round_trip_keeps_the_message_identity_and_shape(Message $message): void
    {
        $restored = $this->roundTrip($message);

        $this->assertInstanceOf($message::class, $restored);
        $this->assertSame($message->getId(), $restored->getId());
        $this->assertSame($message->jsonSerialize(), $restored->jsonSerialize());
    }

    public function test_a_message_with_another_role_keeps_it(): void
    {
        $restored = $this->roundTrip(new Message(MessageRole::DEVELOPER, 'Be terse.'));

        $this->assertSame(Message::class, $restored::class);
        $this->assertSame('developer', $restored->getRole());
    }

    public function test_a_system_message_keeps_its_instruction_join(): void
    {
        $restored = $this->roundTrip(new SystemMessage([new TextContent('Be concise.'), new TextContent('Answer in English.')]));

        $this->assertInstanceOf(SystemMessage::class, $restored);
        $this->assertSame("Be concise.\n\nAnswer in English.", $restored->getContent());
    }

    public function test_a_stored_system_block_loads_as_a_text_block(): void
    {
        // The shape a deprecated SystemContent block was, and still is, stored in
        $restored = $this->roundTrip(new Message(MessageRole::SYSTEM, [(new SystemContent('Be concise.'))->cache()]));

        $this->assertEquals([(new TextContent('Be concise.'))->cache()], $restored->getContentBlocks());
    }

    public function test_a_cache_marker_comes_back_on_its_block(): void
    {
        $message = new UserMessage([
            (new FileContent('file_123', SourceType::ID, 'application/pdf', 'contract.pdf'))->cache(),
            new TextContent('Summarise clause 4.'),
        ]);

        $this->assertEquals($message->getContentBlocks(), $this->roundTrip($message)->getContentBlocks());
    }

    public function test_tool_result_message_keeps_its_metadata(): void
    {
        $message = new ToolResultMessage([(new ToolCall('lookup', 'call-1'))->setResult('found')]);
        $message->addMetadata('source', 'cache');

        $this->assertSame('cache', $this->roundTrip($message)->getMetadata('source'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function metadataValues(): array
    {
        return [
            'integer' => [3],
            'float' => [0.5],
            'boolean' => [true],
            'nested' => [['tags' => ['php', 'ai'], 'depth' => 2]],
        ];
    }

    #[DataProvider('metadataValues')]
    public function test_any_json_metadata_value_survives_the_round_trip(mixed $value): void
    {
        $restored = $this->roundTrip((new UserMessage('Hello'))->addMetadata('value', $value));

        $this->assertSame($value, $restored->getMetadata('value'));
    }

    public function test_metadata_stored_beside_the_message_fields_still_deserializes(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            '__id' => 'msg_legacy',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'content' => 'Hi']],
            'stop_reason' => 'end_turn',
            'attempt' => 2,
            'meta' => 'custom',
        ]);

        $this->assertSame('msg_legacy', $restored->getId());
        $this->assertSame('end_turn', $restored->getMetadata('stop_reason'));
        $this->assertSame(2, $restored->getMetadata('attempt'));
        $this->assertSame('custom', $restored->getMetadata('meta'));
    }

    public function test_usage_stored_before_cache_and_reasoning_counts_still_deserializes(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'assistant',
            'content' => [['type' => 'text', 'content' => 'Hi']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $this->assertSame(0, $restored->getUsage()->cachedInputTokens);
        $this->assertSame(0, $restored->getUsage()->reasoningTokens);
    }

    public function test_structural_keys_are_not_restored_as_metadata(): void
    {
        $restored = $this->roundTrip(new ToolCallMessage(null, [new ToolCall('lookup', 'call-1')]));

        $this->assertNull($restored->getMetadata('type'));
        $this->assertNull($restored->getMetadata('tools'));
    }

    public function test_an_identity_nested_in_the_metadata_never_replaces_the_stored_one(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            '__id' => 'msg_stored',
            'role' => 'user',
            'content' => [['type' => 'text', 'content' => 'Hi']],
            '__meta' => ['__id' => 'msg_forged', 'note' => 'kept'],
        ]);

        $this->assertSame('msg_stored', $restored->getId());
        $this->assertSame('kept', $restored->getMetadata('note'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function emptyContents(): array
    {
        return [
            'null' => [null],
            'missing' => ['missing'],
            'empty list' => [[]],
        ];
    }

    #[DataProvider('emptyContents')]
    public function test_a_message_stored_without_content_has_no_blocks(mixed $content): void
    {
        $data = ['role' => 'user'];
        if ($content !== 'missing') {
            $data['content'] = $content;
        }

        $restored = (new MessageDeserializer())->deserialize($data);

        $this->assertSame([], $restored->getContentBlocks());
    }

    /**
     * Earlier versions left the content out of a media block when it was '' or '0'.
     *
     * @return array<string, array{array<string, mixed>, class-string}>
     */
    public static function mediaBlocksStoredWithoutContent(): array
    {
        return [
            'file' => [['type' => 'file', 'source_type' => 'base64', 'media_type' => 'text/plain', 'filename' => 'empty.txt'], FileContent::class],
            'image' => [['type' => 'image', 'source_type' => 'base64', 'media_type' => 'image/png'], ImageContent::class],
            'audio' => [['type' => 'audio', 'source_type' => 'base64', 'media_type' => 'audio/wav'], AudioContent::class],
            'video' => [['type' => 'video', 'source_type' => 'url'], VideoContent::class],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param class-string $class
     */
    #[DataProvider('mediaBlocksStoredWithoutContent')]
    public function test_a_media_block_stored_without_its_content_loads_as_empty(array $block, string $class): void
    {
        $restored = (new MessageDeserializer())->deserialize(['role' => 'user', 'content' => [$block]]);

        $this->assertInstanceOf($class, $restored->getContentBlocks()[0]);
        $this->assertSame('', $restored->getContentBlocks()[0]->getContent());
    }

    public function test_legacy_string_content_becomes_a_text_block(): void
    {
        $restored = (new MessageDeserializer())->deserialize(['role' => 'assistant', 'content' => 'Plain legacy text']);

        $this->assertInstanceOf(AssistantMessage::class, $restored);
        $this->assertCount(1, $restored->getContentBlocks());
        $this->assertSame(TextContent::class, $restored->getContentBlocks()[0]::class);
        $this->assertSame('Plain legacy text', $restored->getContent());
    }

    public function test_legacy_content_encoded_as_a_json_string_is_decoded(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'user',
            'content' => '[{"type":"text","content":"Encoded twice"}]',
        ]);

        $this->assertSame('Encoded twice', $restored->getContent());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function legacyTextLookingLikeJson(): array
    {
        return [
            'boolean word' => ['true'],
            'scientific number' => ['1e3'],
            'quoted' => ['"quoted"'],
            'json list' => ['[1,2]'],
            'structured output' => ['{"name":"John","age":30}'],
            'list of objects with an unknown type' => ['[{"type":"invoice","amount":10}]'],
            'list of objects with a block type but no content' => ['[{"type":"text","label":"x"}]'],
        ];
    }

    #[DataProvider('legacyTextLookingLikeJson')]
    public function test_legacy_text_that_is_valid_json_is_kept_verbatim(string $text): void
    {
        $restored = (new MessageDeserializer())->deserialize(['role' => 'assistant', 'content' => $text]);

        $this->assertSame(TextContent::class, $restored->getContentBlocks()[0]::class);
        $this->assertSame($text, $restored->getContent());
    }

    public function test_legacy_encoded_media_without_content_still_loads_as_a_block(): void
    {
        // 3.x dropped a media block's empty content, but always kept its source type
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'user',
            'content' => '[{"type":"image","source_type":"url","media_type":"image/png"}]',
        ]);

        $this->assertInstanceOf(ImageContent::class, $restored->getContentBlocks()[0]);
    }

    public function test_an_entry_stored_without_identity_receives_a_fresh_one(): void
    {
        $first = (new MessageDeserializer())->deserialize(['role' => 'user', 'content' => 'Hi']);
        $second = (new MessageDeserializer())->deserialize(['role' => 'user', 'content' => 'Hi']);

        $this->assertStringStartsWith('msg_', $first->getId());
        $this->assertNotSame($first->getId(), $second->getId());
    }

    /**
     * Stored rows are untrusted input: values outside the known vocabularies are rejected, never coerced.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unknownValues(): array
    {
        return [
            'role' => [['role' => 'root', 'content' => 'Hi'], '"root"'],
            'content block type' => [['role' => 'user', 'content' => [['type' => 'script', 'content' => 'x']]], '"script"'],
            'source type' => [['role' => 'user', 'content' => [['type' => 'image', 'content' => 'x', 'source_type' => 'file']]], '"file"'],
            'approval state' => [
                ['role' => 'assistant', 'type' => 'tool_call', 'tools' => [['name' => 'rm', 'inputs' => [], 'approval' => 'yes']]],
                '"yes"',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('unknownValues')]
    public function test_an_unknown_value_is_rejected(array $data, string $value): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage($value . ' is not a valid backing value');

        (new MessageDeserializer())->deserialize($data);
    }

    public function test_a_tool_entry_without_optional_keys_gets_their_defaults(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'user',
            'type' => 'tool_call_result',
            'tools' => [['name' => 'lookup', 'inputs' => ['q' => 'x'], 'result' => 'found']],
        ]);

        $this->assertInstanceOf(ToolResultMessage::class, $restored);
        $call = $restored->getToolCalls()[0];
        $this->assertNull($call->getCallId());
        $this->assertNull($call->getDescription());
        $this->assertFalse($call->isDeferred());
        $this->assertNull($call->getApprovalState());
        $this->assertNull($call->getApprovalReason());
        $this->assertSame(['q' => 'x'], $call->getInputs());
    }

    public function test_a_scalar_tool_result_comes_back_as_a_string(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'user',
            'type' => 'tool_call_result',
            'tools' => [['name' => 'count', 'inputs' => [], 'result' => 42]],
        ]);

        $this->assertInstanceOf(ToolResultMessage::class, $restored);
        $this->assertSame('42', $restored->getToolCalls()[0]->getResult());
    }

    /**
     * @return array<string, array{array<mixed>, bool}>
     */
    public static function malformedResults(): array
    {
        return [
            'error result' => [['is_error' => true, 'blocks' => ['not a block']], true],
            'plain result' => [['rows' => [1, 2]], false],
        ];
    }

    /**
     * @param array<mixed> $stored
     */
    #[DataProvider('malformedResults')]
    public function test_a_result_with_malformed_blocks_loads_without_blocks(array $stored, bool $isError): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'user',
            'type' => 'tool_call_result',
            'tools' => [['name' => 'mail', 'inputs' => [], 'result' => $stored]],
        ]);

        $this->assertInstanceOf(ToolResultMessage::class, $restored);
        $result = $restored->getToolCalls()[0]->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertSame($isError, $result->isError());
        $this->assertSame([], $result->getBlocks());
    }

    public function test_a_falsy_error_marker_is_not_an_error(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            'role' => 'user',
            'type' => 'tool_call_result',
            'tools' => [['name' => 'x', 'inputs' => [], 'result' => ['is_error' => false, 'blocks' => [['type' => 'text', 'content' => 'ok']]]]],
        ]);

        $this->assertInstanceOf(ToolResultMessage::class, $restored);
        $result = $restored->getToolCalls()[0]->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertFalse($result->isError());
        $this->assertSame('ok', $result->getText());
    }

    public function test_every_approval_outcome_survives_the_round_trip(): void
    {
        $calls = [];
        foreach (ApprovalState::cases() as $state) {
            $calls[] = (new ToolCall('rm', $state->value))
                ->setApprovalState($state, 'feedback')
                ->setApprovalReason('Irreversible')
                ->setResult('done');
        }

        $restored = $this->roundTrip(new ToolResultMessage($calls));

        $this->assertInstanceOf(ToolResultMessage::class, $restored);
        foreach ($restored->getToolCalls() as $index => $call) {
            $state = ApprovalState::cases()[$index];
            $this->assertSame($state, $call->getApprovalState());
            $this->assertSame($state === ApprovalState::Rejected ? 'feedback' : null, $call->getRejectReason());
            $this->assertSame('Irreversible', $call->getApprovalReason());
        }
    }

    protected function roundTrip(Message $message): Message
    {
        $stored = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return (new MessageDeserializer())->deserialize($stored);
    }
}
