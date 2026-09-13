<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Streaming;

use Generator;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class SSEEncoderTest extends TestCase
{
    public function test_frame_is_a_data_line_with_the_type_first(): void
    {
        $frame = SSEEncoder::frame(new ProtocolEvent('text-delta', ['id' => 'text_1', 'delta' => 'Hi']));

        $this->assertSame("data: {\"type\":\"text-delta\",\"id\":\"text_1\",\"delta\":\"Hi\"}\n\n", $frame);
    }

    public function test_encode_frames_every_event_and_forwards_the_generator_return(): void
    {
        $events = (static function (): Generator {
            yield new ProtocolEvent('start');
            yield new ProtocolEvent('finish');

            return 'final state';
        })();

        $lines = SSEEncoder::encode($events);

        $this->assertSame(
            ["data: {\"type\":\"start\"}\n\n", "data: {\"type\":\"finish\"}\n\n"],
            iterator_to_array($lines, false),
        );
        $this->assertSame('final state', $lines->getReturn());
    }
}
