<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Streaming;

use Generator;
use JsonException;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_encode;
use function json_decode;
use function substr;

use const NAN;

class SSEEncoderTest extends TestCase
{
    public function test_frame_is_a_data_line_with_the_type_first(): void
    {
        $frame = SSEEncoder::frame(new ProtocolEvent('text-delta', ['id' => 'text_1', 'delta' => 'Hi']));

        $this->assertSame("data: {\"type\":\"text-delta\",\"id\":\"text_1\",\"delta\":\"Hi\"}\n\n", $frame);
    }

    public function test_frame_substitutes_invalid_utf8_instead_of_failing(): void
    {
        $frame = SSEEncoder::frame(new ProtocolEvent('text-delta', ['delta' => "caf\xE9"]));

        $this->assertSame("data: {\"type\":\"text-delta\",\"delta\":\"caf\\ufffd\"}\n\n", $frame);
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

    public function test_encode_raises_an_unencodable_event_inside_the_stream(): void
    {
        $events = (static function (): Generator {
            yield new ProtocolEvent('start');
            try {
                yield new ProtocolEvent('metric', ['value' => NAN]);
            } catch (JsonException $exception) {
                yield new ProtocolEvent('error', ['errorText' => $exception->getMessage()]);
                throw $exception;
            }
            yield new ProtocolEvent('finish');
        })();

        $lines = [];
        $caught = null;
        try {
            foreach (SSEEncoder::encode($events) as $line) {
                $lines[] = $line;
            }
        } catch (JsonException $exception) {
            $caught = $exception;
        }

        // The failure surfaces at the event's own yield, the frames produced in
        // response are still delivered, and the same failure then reaches the edge.
        $this->assertInstanceOf(JsonException::class, $caught);
        $this->assertSame(
            [
                "data: {\"type\":\"start\"}\n\n",
                'data: ' . json_encode(['type' => 'error', 'errorText' => $caught->getMessage()]) . "\n\n",
            ],
            $lines,
        );
    }

    public function test_an_unencodable_stream_event_closes_the_protocol_and_fails_the_run(): void
    {
        $workflow = Workflow::make('test-execution')
            ->addNodes([new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): Generator
                {
                    yield new TextChunk('msg_1', 'Hello');
                    yield new CustomStreamEvent('metric', NAN);

                    return new StopEvent();
                }
            }])
            ->setStreamAdapter(new VercelAIAdapter());

        $types = [];
        $caught = null;
        try {
            foreach (SSEEncoder::encode($workflow->events()) as $line) {
                $types[] = json_decode(substr($line, 6), true)['type'];
            }
        } catch (JsonException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(JsonException::class, $caught);
        $this->assertSame(['start', 'text-start', 'text-delta', 'text-end', 'error'], $types);
        $this->assertSame(WorkflowStatus::Failed, (new WorkflowExecutor())->inspect($workflow)->status);
    }
}
