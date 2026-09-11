<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;
use stdClass;

class AwaitToolResultsNodeTest extends TestCase
{
    public function test_expiry_settles_only_pending_calls_and_preserves_structured_routing(): void
    {
        $state = new AgentState();
        $state->request = new InferenceRequest('Test');
        $state->request->options->outputClass = stdClass::class;
        $event = new AwaitToolResultsEvent(
            completedCalls: [1 => (new ToolCall('local', 'local'))->setResult('local result')],
            deferredCalls: [
                0 => new ToolCall('browser', 'accepted', deferred: true),
                2 => new ToolCall('browser', 'expired', deferred: true),
            ],
        );
        $memoizer = WorkflowTestStore::memoizer(new InMemoryPersistence(), 'timeout', 'await');
        $memoizer->memo('result.accepted', fn (): array => ['result' => 'already accepted']);
        $node = new AwaitToolResultsNode(new InMemoryChatHistory());
        $node->setWorkflowContext(new NodeContext($state, $event, timedOut: true, memoizer: $memoizer));
        $stream = $node($event, $state);
        $chunks = iterator_to_array($stream);

        $this->assertInstanceOf(StructuredInferenceEvent::class, $stream->getReturn());
        $this->assertCount(2, $chunks);
        $result = $state->request->messages[0];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $calls = $result->getToolCalls();
        $this->assertSame('local result', $calls[1]->getResult());
        $this->assertSame('already accepted', $calls[0]->getResult());
        $this->assertInstanceOf(ToolOutput::class, $calls[2]->getResult());
        $this->assertTrue($calls[2]->getResult()->isError());
        $this->assertStringContainsString('timed out', $calls[2]->getResult()->getText());
    }

    public function test_recovery_after_all_results_are_memoized_finishes_without_another_wait(): void
    {
        $state = new AgentState();
        $state->request = new InferenceRequest('Test');
        $event = new AwaitToolResultsEvent([], [new ToolCall('browser', 'a', deferred: true)]);
        $memoizer = WorkflowTestStore::memoizer(new InMemoryPersistence(), 'recovery', 'await');
        $memoizer->memo('result.a', fn (): array => ['result' => 'accepted before crash']);
        $node = new AwaitToolResultsNode(new InMemoryChatHistory());
        $node->setWorkflowContext(new NodeContext($state, $event, memoizer: $memoizer));
        $stream = $node($event, $state);
        iterator_to_array($stream);

        $result = $state->request->messages[0];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertSame('accepted before crash', $result->getToolCalls()[0]->getResult());
    }
}
