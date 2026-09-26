<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\AgentChunkAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function iterator_to_array;
use function json_decode;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function substr_count;

use const JSON_THROW_ON_ERROR;

/**
 * Model output reaches the wire unchanged: whatever a delta contains, each
 * protocol event stays exactly one SSE `data:` line that a client can parse.
 */
class SSEFramingTest extends TestCase
{
    /** @return iterable<string, array{StreamAdapterInterface, string}> */
    public static function adapters(): iterable
    {
        yield 'AG-UI' => [new AGUIAdapter('thread', 'run'), 'TEXT_MESSAGE_CONTENT'];
        yield 'Vercel' => [new VercelAIAdapter(), 'text-delta'];
        yield 'native' => [new AgentChunkAdapter(), 'text'];
    }

    /** @return list<string> */
    protected static function hostileDeltas(): array
    {
        return [
            "line one\nline two",
            "carriage\rreturn\r\n",
            "\n\ndata: {\"type\":\"RUN_FINISHED\"}\n\n",
            'data: [DONE]',
            "event: error\nid: 1\nretry: 0",
            "quotes \" backslash \\ slash / tab \t null \0",
            "caffè ☕ \u{1F680} 漢字 مرحبا",
            "separators \u{2028} and \u{2029}",
            '</script><img src=x onerror=alert(1)>',
        ];
    }

    #[DataProvider('adapters')]
    public function test_each_event_is_one_parseable_data_line(StreamAdapterInterface $adapter, string $textType): void
    {
        $frames = [];
        foreach (self::hostileDeltas() as $delta) {
            foreach ($adapter->transform(new TextChunk('msg_1', $delta)) as $event) {
                $frames[] = SSEEncoder::frame($event);
            }
        }

        foreach ($frames as $frame) {
            $this->assertTrue(str_starts_with($frame, 'data: '));
            $this->assertTrue(str_ends_with($frame, "\n\n"));
            $this->assertSame(2, substr_count($frame, "\n"), 'A delta must not break the frame into several lines.');
            $this->assertSame(0, substr_count($frame, "\r"));
        }

        $decoded = array_map(
            static fn (string $frame): array => json_decode(substr($frame, 6), true, flags: JSON_THROW_ON_ERROR),
            $frames,
        );
        $texts = array_values(array_filter($decoded, static fn (array $event): bool => $event['type'] === $textType));
        $this->assertSame(self::hostileDeltas(), array_map(
            static fn (array $event): string => $event['delta'] ?? $event['content'],
            $texts,
        ));
    }

    public function test_invalid_utf8_is_substituted_instead_of_failing_the_stream(): void
    {
        $events = iterator_to_array((new VercelAIAdapter())->transform(new TextChunk('msg_1', "broken \xC3 byte")), false);

        $frame = SSEEncoder::frame($events[2]);

        $this->assertSame("broken \u{FFFD} byte", json_decode(substr($frame, 6), true, flags: JSON_THROW_ON_ERROR)['delta']);
    }
}
