<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use JsonException;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Chat\History\Stub\RecordingStreamWrapper;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function file_put_contents;
use function pack;
use function str_repeat;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const NAN;

class TokenCounterTest extends TestCase
{
    /**
     * Characters are the role plus the JSON payload of each text block, four per token, rounded up.
     *
     * @return array<string, array{Message, int}>
     */
    public static function textMessages(): array
    {
        return [
            // 4 role chars
            'no content' => [new UserMessage(null), 1],
            // 4 + 43 chars: {"type":"text","content":"Hello","meta":[]}
            'user text' => [new UserMessage('Hello'), 12],
            // 9 + 43 chars
            'assistant text' => [new AssistantMessage('Hello'), 13],
            // 9 + 58 chars: {"type":"reasoning","content":"Hello","meta":[],"id":null}
            'reasoning' => [new AssistantMessage([new ReasoningContent('Hello')]), 17],
            // 4 + 38 + 1000 chars
            'long text' => [new UserMessage(str_repeat('a', 1000)), 261],
            // 4 + 2 * 43 chars
            'several text blocks' => [new UserMessage([new TextContent('Hello'), new TextContent('Hello')]), 23],
        ];
    }

    #[DataProvider('textMessages')]
    public function test_text_is_estimated_from_its_characters(Message $message, int $tokens): void
    {
        $this->assertSame($tokens, (new TokenCounter())->count($message));
    }

    public function test_the_characters_per_token_ratio_is_configurable(): void
    {
        // 47 chars at 8 per token
        $this->assertSame(6, (new TokenCounter(8.0))->count(new UserMessage('Hello')));
    }

    /**
     * @return array<string, array{ContentBlockInterface}>
     */
    public static function unmeasuredBlocks(): array
    {
        return [
            'file' => [new FileContent('https://example.com/a.pdf', SourceType::URL)],
            'audio' => [new AudioContent('https://example.com/a.mp3', SourceType::URL)],
            'video' => [new VideoContent('https://example.com/a.mp4', SourceType::URL)],
        ];
    }

    #[DataProvider('unmeasuredBlocks')]
    public function test_media_that_cannot_be_measured_counts_as_a_fixed_cost(ContentBlockInterface $block): void
    {
        // 4 role chars + 200 tokens worth of characters
        $this->assertSame(201, (new TokenCounter())->count(new UserMessage([$block])));
    }

    /**
     * 85 base tokens plus 170 per 512px tile, after fitting in 2048px and shrinking the short side to 768px.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function imageSizes(): array
    {
        return [
            'tiny' => [1, 1, 256],
            'one tile' => [512, 512, 256],
            'two tiles' => [1024, 512, 426],
            'short side shrunk to 768' => [1024, 1024, 766],
            'short side under 768' => [800, 600, 766],
            // 1600x1000 shrinks to 1228x768 (3x2 tiles); the portrait twin to 768x1228.
            'wide, short side shrunk to 768' => [1600, 1000, 1106],
            'tall, short side shrunk to 768' => [1000, 1600, 1106],
            'wide, fitted in 2048' => [4096, 1024, 766],
            'tall, fitted in 2048' => [100, 3000, 766],
            'huge square' => [8192, 8192, 766],
            // 5000x2 fits as 2048x1 (4x1 tiles), never 0px tall; the portrait twin as 1x2048.
            'wider than 2048:1' => [5000, 2, 766],
            'taller than 1:2048' => [2, 5000, 766],
        ];
    }

    #[DataProvider('imageSizes')]
    public function test_a_base64_image_is_measured_by_its_tiles(int $width, int $height, int $tokens): void
    {
        $image = new ImageContent(base64_encode(self::pngHeader($width, $height)), SourceType::BASE64, 'image/png');

        $this->assertSame($tokens, (new TokenCounter())->count(new UserMessage([$image])));
    }

    /**
     * @return array<string, array{ImageContent}>
     */
    public static function imagesWithoutReadableSize(): array
    {
        return [
            'url' => [new ImageContent('https://example.com/cat.png', SourceType::URL)],
            'provider file id' => [new ImageContent('file_011CNha8iCJcU1wXNR6q4V8w', SourceType::ID)],
            'id that is also valid base64' => [new ImageContent('file1234', SourceType::ID)],
            'data uri instead of a bare payload' => [new ImageContent('data:image/png;base64,' . base64_encode(self::pngHeader(512, 512)), SourceType::BASE64)],
            'empty payload' => [new ImageContent('', SourceType::BASE64)],
            'text payload' => [new ImageContent(base64_encode('hello'), SourceType::BASE64, 'image/png')],
            'svg payload' => [new ImageContent(base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'), SourceType::BASE64, 'image/svg+xml')],
            'truncated png' => [new ImageContent(base64_encode("\x89PNG\r\n\x1a\n"), SourceType::BASE64, 'image/png')],
            'zero height' => [new ImageContent(base64_encode(self::pngHeader(100, 0)), SourceType::BASE64, 'image/png')],
            'zero width' => [new ImageContent(base64_encode(self::pngHeader(0, 100)), SourceType::BASE64, 'image/png')],
            'zero width and height' => [new ImageContent(base64_encode(self::pngHeader(0, 0)), SourceType::BASE64, 'image/png')],
            'oversized width, zero height' => [new ImageContent(base64_encode(self::pngHeader(5000, 0)), SourceType::BASE64, 'image/png')],
        ];
    }

    #[DataProvider('imagesWithoutReadableSize')]
    public function test_an_image_without_a_readable_size_is_priced_like_a_1024_square(ImageContent $image): void
    {
        // 4 role chars + a 1024x1024 image: 85 + 4 tiles x 170 tokens
        $this->assertSame(766, (new TokenCounter())->count(new UserMessage([$image])));
    }

    public function test_counting_never_opens_an_image_url(): void
    {
        RecordingStreamWrapper::$opened = [];
        stream_wrapper_register('probe', RecordingStreamWrapper::class);

        try {
            (new TokenCounter())->count(new UserMessage([new ImageContent('probe://169.254.169.254/latest/meta-data', SourceType::URL)]));
        } finally {
            stream_wrapper_unregister('probe');
        }

        $this->assertSame([], RecordingStreamWrapper::$opened);
    }

    public function test_an_image_id_is_never_read_from_disk(): void
    {
        $path = sys_get_temp_dir() . '/token-counter-' . uniqid() . '.png';
        file_put_contents($path, self::pngHeader(1, 1));

        try {
            $tokens = (new TokenCounter())->count(new UserMessage([new ImageContent($path, SourceType::ID)]));
        } finally {
            unlink($path);
        }

        // Read from disk, the 1x1 PNG would cost 256 and reveal that the file exists
        $this->assertSame(766, $tokens);
    }

    public function test_a_tool_result_counts_its_results_and_call_ids(): void
    {
        $message = new ToolResultMessage([
            (new ToolCall('a', 'call_1'))->setResult(str_repeat('x', 100)),
            (new ToolCall('b'))->setResult(str_repeat('y', 50)),
        ]);

        // 4 role chars + 100 + 6 + 50
        $this->assertSame(40, (new TokenCounter())->count($message));
    }

    public function test_a_multimodal_tool_result_counts_its_text(): void
    {
        $message = new ToolResultMessage([(new ToolCall('a'))->setResult(ToolOutput::text(str_repeat('x', 100)))]);

        // 4 role chars + 100
        $this->assertSame(26, (new TokenCounter())->count($message));
    }

    public function test_a_tool_result_counts_multibyte_characters_not_bytes(): void
    {
        $message = new ToolResultMessage([(new ToolCall('a'))->setResult(str_repeat('日', 100))]);

        $this->assertSame(26, (new TokenCounter())->count($message));
    }

    public function test_system_text_is_counted_like_any_text(): void
    {
        $counter = new TokenCounter();
        $text = str_repeat('a', 4000);

        // The block JSON differs only by its type name, "system" instead of "text"
        $this->assertSame(1012, $counter->count(new SystemMessage($text)));
        $this->assertSame(1011, $counter->count(new SystemMessage([new TextContent($text)])));
    }

    public function test_a_tool_call_counts_its_names_call_ids_and_arguments(): void
    {
        $message = new ToolCallMessage(null, [
            new ToolCall('write_file', 'call-1', ['path' => '/tmp/日本.txt', 'content' => str_repeat('a', 400)]),
            new ToolCall('list'),
        ]);

        // 9 role chars + 10 + 6 + 435 characters of JSON arguments (slashes and Unicode unescaped) + 4 + 2 for "[]"
        $this->assertSame(117, (new TokenCounter())->count($message));
    }

    public function test_invalid_utf8_in_tool_call_arguments_is_counted_without_failing(): void
    {
        $message = new ToolCallMessage(null, [new ToolCall('write_file', 'call-1', ['content' => "caf\xE9"])]);

        // The invalid byte counts as one substituted character: 9 + 10 + 6 + 18
        $this->assertSame(11, (new TokenCounter())->count($message));
    }

    public function test_invalid_utf8_in_text_is_counted_without_failing(): void
    {
        // Each invalid byte counts as a replacement character, escaped like any non-ASCII text: 4 + 38 + 7 + 2 * 6 + 8
        $this->assertSame(18, (new TokenCounter())->count(new UserMessage("binary \xFF\xFE payload")));
    }

    public function test_a_text_block_that_cannot_be_encoded_throws_a_json_exception(): void
    {
        $this->expectException(JsonException::class);

        (new TokenCounter())->count(new UserMessage((new TextContent('Hello'))->setMetadata(['score' => NAN])));
    }

    /**
     * The IHDR header is all getimagesize() reads to size a PNG.
     */
    protected static function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00" . pack('N', 0);
    }
}
