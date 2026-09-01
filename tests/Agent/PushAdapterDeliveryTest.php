<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tests\Agent\Stub\ParityAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Channel\CallbackChannel;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function iterator_to_array;

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
}
