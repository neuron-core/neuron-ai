<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WorkflowExecutionIntentTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executions(): iterable
    {
        yield 'recover eagerly' => [false];
        yield 'recover streaming' => [true];
    }

    #[DataProvider('executions')]
    public function test_failed_execution_is_recovered_by_plain_terminals(bool $streaming): void
    {
        MemoizingNode::resetOperationCount();
        $persistence = new InMemoryPersistence();
        $failed = Workflow::make('intent')->setPersistence($persistence)->addNode(new MemoizingNode(true));
        try {
            $failed->run();
            $this->fail('Expected the first execution to fail after its durable memo.');
        } catch (RuntimeException) {
        }

        $workflow = Workflow::make('intent')->setPersistence($persistence)->addNode(new MemoizingNode());
        if ($streaming) {
            $events = $workflow->events();
            iterator_to_array($events);
            $state = $events->getReturn();
        } else {
            $state = $workflow->run();
        }

        $this->assertSame($failed->getRunId(), $state->getRunId());
        $this->assertSame(1, MemoizingNode::getOperationCount());
        $this->assertSame('computed_1', $state->get('memo_result'));
    }

    public function test_resume_is_read_only_and_can_retrieve_a_retained_completion(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = Workflow::make('intent')->setPersistence($persistence)
            ->addNode(new MemoizingNode())->retainCompletionUntilAcknowledged();
        $completed = $workflow->run();
        $reader = Workflow::make('intent')->setPersistence($persistence);
        $before = serialize($persistence);
        $this->assertSame($reader, $reader->resume(expectedRunId: $completed->getRunId()));
        $this->assertSame($before, serialize($persistence));
        $this->assertSame($completed->get('memo_result'), $reader->run()->get('memo_result'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function conflictingOperations(): iterable
    {
        foreach (['resume', 'signal'] as $first) {
            foreach (['resume', 'signal'] as $second) {
                yield "$first then $second" => [$first, $second];
            }
        }
    }

    #[DataProvider('conflictingOperations')]
    public function test_staged_operations_cannot_be_combined(string $first, string $second): void
    {
        $workflow = Workflow::make();
        $workflow->$first(...($first === 'signal' ? ['event'] : []));
        $this->expectException(WorkflowException::class);
        $workflow->$second(...($second === 'signal' ? ['event'] : []));
    }
}
