<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

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
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function pack;
use function str_repeat;

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
        ];
    }

    #[DataProvider('imageSizes')]
    public function test_a_base64_image_is_measured_by_its_tiles(int $width, int $height, int $tokens): void
    {
        $image = new ImageContent(base64_encode($this->pngHeader($width, $height)), SourceType::BASE64, 'image/png');

        $this->assertSame($tokens, (new TokenCounter())->count(new UserMessage([$image])));
    }

    public function test_a_data_uri_image_is_measured_like_its_base64_payload(): void
    {
        $payload = base64_encode($this->pngHeader(1024, 512));
        $counter = new TokenCounter();

        $this->assertSame(
            $counter->count(new UserMessage([new ImageContent($payload, SourceType::BASE64)])),
            $counter->count(new UserMessage([new ImageContent('data:image/png;base64,' . $payload, SourceType::BASE64)]))
        );
    }

    public function test_an_image_that_cannot_be_measured_is_counted_without_error(): void
    {
        // Its weight is deliberately not pinned: only that counting never fails.
        $image = new ImageContent('file-does-not-exist', SourceType::ID);

        $this->assertGreaterThanOrEqual(1, (new TokenCounter())->count(new UserMessage([$image])));
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

    /**
     * The IHDR header is all getimagesize() reads to size a PNG.
     */
    protected function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x02\x00\x00\x00" . pack('N', 0);
    }
}
