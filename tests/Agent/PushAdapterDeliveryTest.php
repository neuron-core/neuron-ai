<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Generator;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Agent\Stub\ParityAdapter;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function count;
use function iterator_to_array;
use function json_decode;
use function json_encode;

class PushAdapterDeliveryTest extends TestCase
{
    public function test_stream_remains_lazy_with_every_channel_configuration(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$adapter, $channel]) {
            $provider = new FakeAIProvider(new AssistantMessage('Hello'));
            $agent = Agent::make()
                ->setStreamAdapter($adapter ? new ParityAdapter() : null)
                ->setChannel($channel ? new FakeChannel() : null);
            $agent->setAiProvider($provider);

            $stream = $agent->stream(new UserMessage('Hi'));

            $this->assertInstanceOf(Generator::class, $stream);
            $provider->assertCallCount(0);
            $this->assertNotEmpty(iterator_to_array($stream));
            $this->assertSame('Hello', $stream->getReturn()->getMessage()->getContent());
            $provider->assertCallCount(1);
        }
    }

    public function test_push_output_is_byte_identical_to_the_pull_path(): void
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

        // Push: the same Workflow-owned adapter path also delivers each event
        // to the channel's send port.
        $sink = [];
        $pushAgent = Agent::make();
        $pushAgent->setAiProvider(
            (new FakeAIProvider(new AssistantMessage('Hello world, streaming bytes')))->setStreamChunkSize(5)
        );
        $pushAgent->setStreamAdapter(new ParityAdapter());
        $pushAgent->setChannel(new CallbackChannel(
            onSend: function (ProtocolEvent $event) use (&$sink): void {
                $sink[] = $event;
            },
        ));

        $state = $pushAgent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(new \NeuronAI\Agent\Events\AgentStartEvent([new UserMessage('Hi')], new \NeuronAI\Agent\AgentRunOptions(stream: true))));

        $this->assertInstanceOf(AgentState::class, $state);
        $this->assertSame('Hello world, streaming bytes', $state->getMessage()->getContent());

        $this->assertGreaterThan(2, count($sink), 'The stream should carry protocol events beyond start/end');
        $this->assertEquals($pulled, $sink);
    }

    public function test_zero_item_run_still_emits_start_and_end_matching_pull(): void
    {
        // A zero-item stream (empty content → no TextChunks) still frames the
        // run with the protocol start/end sequences; so must the push path,
        // whose finishDelivery() emits start+end on completion even though no
        // item was ever delivered to send().
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
            onSend: function (ProtocolEvent $event) use (&$sink): void {
                $sink[] = $event;
            },
        ));

        $pushAgent->chat(new UserMessage('Hi'));

        $this->assertSame(['start', 'end'], array_map(static fn (ProtocolEvent $event): string => $event->type, $pulled));
        $this->assertEquals($pulled, $sink);
    }

    public function test_suspended_stream_delivers_frames_and_returns_the_interrupted_state(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'geolocation_get';

            protected ?string $description = 'Get the browser geolocation';

            protected function approvalPolicy(): string
            {
                return 'Location access needs consent';
            }

            public function __invoke(bool $save = true): string
            {
                return 'lat/long';
            }
        };

        $channel = new FakeChannel();
        $agent = Agent::make(threadId: 'thread-1')
            ->setPersistence(new InMemoryPersistence())
            ->setStreamAdapter(new AGUIAdapter('thread-1', 'run-1'))
            ->setChannel($channel);
        $agent->setAiProvider(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('geolocation_get', 'call_1', ['save' => true]),
        ])));
        $agent->addTool($tool);

        $state = $agent->run(\NeuronAI\Workflow\Executor\ExecutionRequest::start(new \NeuronAI\Agent\Events\AgentStartEvent([new UserMessage('Where am I?')], new \NeuronAI\Agent\AgentRunOptions(stream: true))));

        $this->assertTrue($state->isInterrupted());
        $this->assertCount(1, $channel->getSuspensions());
        $this->assertSame([], $channel->getCompletions());

        // Approval proposals stay in interrupt metadata, off the executable tool channel.
        $events = array_map(static fn (ProtocolEvent $event): array => json_decode(json_encode($event), true), $channel->getSent());
        $this->assertSame(
            ['RUN_STARTED', 'STATE_SNAPSHOT', 'MESSAGES_SNAPSHOT', 'RUN_FINISHED'],
            array_column($events, 'type'),
        );
        $interrupt = $events[3]['outcome']['interrupts'][0];
        $this->assertSame('confirmation', $interrupt['reason']);
        $this->assertSame('call_1', $interrupt['id']);
        $this->assertSame('Location access needs consent', $interrupt['message']);
        $this->assertSame(['save' => true], $interrupt['metadata']['inputs']);
    }
}
