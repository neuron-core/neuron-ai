<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AwaitToolResultsEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\AwaitToolResultsNode;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;
use function iterator_to_array;

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
        $node = new AwaitToolResultsNode();
        $node->setWorkflowContext(new NodeContext(timedOut: true, memoizer: $memoizer));
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
        $node = new AwaitToolResultsNode();
        $node->setWorkflowContext(new NodeContext(memoizer: $memoizer));
        $stream = $node($event, $state);
        iterator_to_array($stream);

        $result = $state->request->messages[0];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertSame('accepted before crash', $result->getToolCalls()[0]->getResult());
    }

    /**
     * @param array<int, ToolCall> $completed
     * @param array<int, ToolCall> $deferred
     * @param array<string, mixed>|null $payload
     * @return array{AgentState, list<object>, Event}
     */
    protected function resume(array $completed, array $deferred, ?array $payload): array
    {
        $state = new AgentState();
        $state->request = new InferenceRequest('Test');
        $node = new AwaitToolResultsNode();
        $node->setWorkflowContext(new NodeContext(
            payload: $payload,
            memoizer: WorkflowTestStore::memoizer(new InMemoryPersistence(), 'resume', 'await'),
        ));

        $stream = $node(new AwaitToolResultsEvent($completed, $deferred), $state);
        $chunks = iterator_to_array($stream, false);

        return [$state, $chunks, $stream->getReturn()];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null, bool}>
     */
    public static function externalOutcomes(): iterable
    {
        yield 'string passes through' => [['result' => 'Plain text'], 'Plain text', false];
        yield 'multibyte string passes through' => [['result' => 'Café 🚀'], 'Café 🚀', false];
        yield 'numeric string stays a string' => [['result' => '42'], '42', false];
        yield 'zero is encoded' => [['result' => 0], '0', false];
        yield 'false is encoded' => [['result' => false], 'false', false];
        yield 'null is encoded' => [['result' => null], 'null', false];
        yield 'list is encoded' => [['result' => [1, 'two']], '[1,"two"]', false];
        yield 'object is encoded' => [['result' => ['title' => 'Home', 'tags' => []]], '{"title":"Home","tags":[]}', false];
        yield 'error becomes an error output' => [['error' => 'User cancelled'], 'User cancelled', true];
    }

    /**
     * @param array<string, mixed> $outcome
     */
    #[DataProvider('externalOutcomes')]
    public function test_external_outcomes_become_tool_results(array $outcome, string $expected, bool $isError): void
    {
        $call = new ToolCall('browser', 'external', deferred: true);

        $this->resume([], [$call], ['external' => $outcome]);

        $result = $call->getResult();
        if ($isError) {
            $this->assertInstanceOf(ToolOutput::class, $result);
            $this->assertTrue($result->isError());
            $this->assertSame($expected, $result->getText());
        } else {
            $this->assertSame($expected, $result);
        }
    }

    public function test_results_merge_with_local_calls_in_the_original_order(): void
    {
        $local = (new ToolCall('lookup', 'local'))->setResult('local result');
        $first = new ToolCall('browser', 'first', deferred: true);
        $last = new ToolCall('browser', 'last', deferred: true);

        [$state, $chunks, $next] = $this->resume([1 => $local], [2 => $last, 0 => $first], [
            'last' => ['result' => 'L'],
            'first' => ['result' => 'F'],
        ]);

        $this->assertInstanceOf(AIInferenceEvent::class, $next);
        $this->assertNotInstanceOf(StructuredInferenceEvent::class, $next);
        $message = $state->request->messages[0];
        $this->assertCount(1, $state->request->messages);
        $this->assertInstanceOf(ToolResultMessage::class, $message);
        $this->assertSame([$first, $local, $last], $message->getToolCalls());
        // Only the external calls stream a result: the local one already did.
        $this->assertSame([$last, $first], array_map(static fn (ToolResultChunk $chunk): ToolCall => $chunk->tool, $chunks));
    }

    public function test_a_result_for_a_foreign_call_is_refused_at_the_node(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Tool result 'forged' does not belong to this deferred batch.");

        $this->resume([], [new ToolCall('browser', 'external', deferred: true)], ['forged' => ['result' => 'x']]);
    }

    public function test_a_partial_delivery_suspends_again_for_the_remaining_calls(): void
    {
        $answered = new ToolCall('browser', 'answered', deferred: true);
        $waiting = new ToolCall('browser', 'waiting', deferred: true);

        try {
            $this->resume([], [$answered, $waiting], ['answered' => ['result' => 'ok']]);
            $this->fail('An unanswered call must suspend the node again.');
        } catch (WorkflowInterrupt $interrupt) {
            $request = $interrupt->getRequest();
            $this->assertInstanceOf(ToolResultsRequest::class, $request);
            $this->assertSame([$waiting], $request->getToolCalls());
            $this->assertSame(['answered' => ['result' => 'ok']], $request->getResults());
        }
    }
}
