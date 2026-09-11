<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Agent;
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
use PHPUnit\Framework\TestCase;
use function array_column;
use function array_map;
use function count;
use function implode;
use function iterator_to_array;
use function json_decode;
use function substr;

class PushAdapterDeliveryTest extends TestCase
{
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

    public function test_zero_item_run_still_emits_start_and_end_matching_pull(): void
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

    public function test_suspended_stream_frames_are_identical_between_pull_and_push(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'geolocation_get';

            protected ?string $description = 'Get the browser geolocation';

            protected function approvalPolicy(array $inputs): string
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

        $generator = $agent->stream(new UserMessage('Where am I?'));
        $pulled = iterator_to_array($generator, false);
        $state = $generator->getReturn();

        $this->assertTrue($state->isInterrupted());
        $this->assertSame($pulled, $channel->lines);
        $this->assertCount(1, $channel->suspendedStates);
        $this->assertSame([], $channel->completions);

        // Approval proposals stay in interrupt metadata, off the executable tool channel.
        $events = array_map(static fn (string $line): array => json_decode(substr($line, 6, -2), true), $pulled);
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
