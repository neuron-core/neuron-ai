<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Channel;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Channel\StreamAdapterChannel;
use PHPUnit\Framework\TestCase;
use function count;
use function implode;

/**
 * Deterministic protocol adapter: unlike AGUIAdapter it generates no random
 * ids, so two independent instances produce identical output for the same
 * item sequence — which is what byte-parity needs (adapters are stateful and
 * must never be shared between the pull side and a channel).
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

class StreamAdapterChannelTest extends TestCase
{
    public function testPushOutputIsByteIdenticalToThePullPath(): void
    {
        // Pull: the caller drains AgentHandler::events($adapter).
        $pullAgent = Agent::make()->setAiProvider(
            (new FakeAIProvider(new AssistantMessage('Hello world, streaming bytes')))->setStreamChunkSize(5)
        );

        $pulled = [];
        foreach ($pullAgent->stream(new UserMessage('Hi'))->events(new ParityAdapter()) as $line) {
            $pulled[] = $line;
        }

        // Push: nobody holds the adapter — the channel renders to the sink
        // while the handler is consumed internally. Two adapter instances.
        $sink = [];
        $pushAgent = Agent::make();
        $pushAgent->setAiProvider(
            (new FakeAIProvider(new AssistantMessage('Hello world, streaming bytes')))->setStreamChunkSize(5)
        );
        $pushAgent->setChannel(new StreamAdapterChannel(
            new ParityAdapter(),
            function (string $line) use (&$sink): void {
                $sink[] = $line;
            },
        ));

        $pushAgent->stream(new UserMessage('Hi'))->run();

        $this->assertGreaterThan(2, count($sink), 'The stream should carry protocol lines beyond start/end');
        $this->assertSame(implode('', $pulled), implode('', $sink));
    }

    public function testZeroItemRunStillEmitsStartAndEndMatchingPull(): void
    {
        // chat() yields no stream chunks; the pull path still frames the run
        // with the protocol start/end sequences — so must the push path.
        $pullAgent = Agent::make()->setAiProvider(
            new FakeAIProvider(new AssistantMessage('Silent answer'))
        );

        $pulled = [];
        foreach ($pullAgent->chat(new UserMessage('Hi'))->events(new ParityAdapter()) as $line) {
            $pulled[] = $line;
        }

        $sink = [];
        $pushAgent = Agent::make();
        $pushAgent->setAiProvider(
            new FakeAIProvider(new AssistantMessage('Silent answer'))
        );
        $pushAgent->setChannel(new StreamAdapterChannel(
            new ParityAdapter(),
            function (string $line) use (&$sink): void {
                $sink[] = $line;
            },
        ));

        $pushAgent->chat(new UserMessage('Hi'))->run();

        $this->assertSame(["start\n", "end\n"], $pulled);
        $this->assertSame($pulled, $sink);
    }
}
