<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Channel;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Channel\CallbackChannel;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function iterator_to_array;

/**
 * Deterministic protocol adapter: unlike AGUIAdapter it generates no random
 * ids, so two independent instances produce identical output for the same
 * item sequence — which is what byte-parity needs.
 */
class ParityAdapter implements StreamAdapterInterface
{
    public function start(): iterable
    {
        yield "start\n";
    }

    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof TextChunk) {
            yield 'text:' . $chunk->content . "\n";
        }
    }

    public function end(): iterable
    {
        yield "end\n";
    }
}

class PushAdapterDeliveryTest extends TestCase
{
    public function testPushOutputIsByteIdenticalToThePullPath(): void
    {
        // Pull: the caller drains the Workflow-managed adapter output.
        $pullAgent = Agent::make()->setStreamAdapter(new ParityAdapter());
        $pullAgent->setAiProvider(
            (new FakeAIProvider(new AssistantMessage('Hello world, streaming bytes')))->setStreamChunkSize(5)
        );

        $pulled = [];
        foreach ($pullAgent->stream(new UserMessage('Hi')) as $line) {
            $pulled[] = $line;
        }

        // Push: the same Workflow-owned adapter path also delivers each line
        // to the channel's sendLine port.
        $sink = [];
        $pushAgent = Agent::make();
        $pushAgent->setAiProvider(
            (new FakeAIProvider(new AssistantMessage('Hello world, streaming bytes')))->setStreamChunkSize(5)
        );
        $pushAgent->setStreamAdapter(new ParityAdapter());
        $pushAgent->setChannel(new CallbackChannel(
            onSendLine: function (string $line) use (&$sink): void {
                $sink[] = $line;
            },
        ));

        // Stream mode so ChatNode yields chunks the channel can adapt; the
        // pull-side output is discarded — only the channel sink is asserted.
        iterator_to_array($pushAgent->stream(new UserMessage('Hi')));

        $this->assertGreaterThan(2, count($sink), 'The stream should carry protocol lines beyond start/end');
        $this->assertSame(implode('', $pulled), implode('', $sink));
    }

    public function testZeroItemRunStillEmitsStartAndEndMatchingPull(): void
    {
        // A zero-item stream (empty content → no TextChunks) still frames the
        // run with the protocol start/end sequences; so must the push path,
        // whose finishDelivery() emits start+end on completion even though no
        // item was ever delivered to sendLine().
        $pullAgent = Agent::make()->setStreamAdapter(new ParityAdapter());
        $pullAgent->setAiProvider(new FakeAIProvider(new AssistantMessage('')));

        $pulled = [];
        foreach ($pullAgent->stream(new UserMessage('Hi')) as $line) {
            $pulled[] = $line;
        }

        $sink = [];
        $pushAgent = Agent::make();
        $pushAgent->setAiProvider(
            new FakeAIProvider(new AssistantMessage(''))
        );
        $pushAgent->setStreamAdapter(new ParityAdapter());
        $pushAgent->setChannel(new CallbackChannel(
            onSendLine: function (string $line) use (&$sink): void {
                $sink[] = $line;
            },
        ));

        $pushAgent->chat(new UserMessage('Hi'));

        $this->assertSame(["start\n", "end\n"], $pulled);
        $this->assertSame($pulled, $sink);
    }
}
