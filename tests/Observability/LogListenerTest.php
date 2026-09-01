<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\LogObserver;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Observability\Events\MemoryRecalled;
use NeuronAI\Observability\Events\MemoryRecalling;
use NeuronAI\Observability\Events\MemoryStored;
use NeuronAI\Observability\Events\MemoryStoring;
use NeuronAI\Observability\Events\Retrieving;
use NeuronAI\Observability\Events\WorkflowInterrupted;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_column;

class LogListenerTest extends TestCase
{
    /**
     * @return AbstractLogger&object{records: array<int, array{level: mixed, message: string, context: array<string, mixed>}>}
     */
    protected function recordingLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    public function test_log_listener_logs_every_event_with_serialized_context(): void
    {
        $logger = $this->recordingLogger();

        Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->subscribe(ObservabilityEvent::class, new LogListener($logger))
            ->run();

        $messages = array_column($logger->records, 'message');
        $this->assertContains('workflow-start', $messages);
        $this->assertContains('workflow-node-start', $messages);
        $this->assertContains('workflow-end', $messages);

        foreach ($logger->records as $record) {
            if ($record['message'] === 'workflow-node-start') {
                $this->assertArrayHasKey('node', $record['context']);
                return;
            }
        }

        $this->fail('No workflow-node-start record found.');
    }

    public function test_deprecated_log_observer_still_logs_through_observe(): void
    {
        $logger = $this->recordingLogger();

        Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->observe(new LogObserver($logger))
            ->run();

        $messages = array_column($logger->records, 'message');
        $this->assertContains('workflow-start', $messages);
        $this->assertContains('workflow-end', $messages);
    }

    public function test_memory_events_log_only_safe_monitoring_context(): void
    {
        $logger = $this->recordingLogger();
        $listener = new LogListener($logger);

        $listener(new MemoryRecalling());
        $listener(new MemoryRecalled(5));
        $listener(new MemoryStoring());
        $listener(new MemoryStored());

        $this->assertSame([
            [
                'level' => 'info',
                'message' => 'memory-recalling',
                'context' => [],
            ],
            [
                'level' => 'info',
                'message' => 'memory-recalled',
                'context' => ['memory-count' => 5],
            ],
            [
                'level' => 'info',
                'message' => 'memory-storing',
                'context' => [],
            ],
            [
                'level' => 'info',
                'message' => 'memory-stored',
                'context' => [],
            ],
        ], $logger->records);
    }

    public function test_interruption_logs_the_complete_request_list(): void
    {
        $state = new WorkflowState();
        $state->markAsSuspended([
            1 => (new ApprovalRequest('first'))->withId(1),
            2 => (new ApprovalRequest('second'))->withId(2),
        ]);

        $logger = $this->recordingLogger();
        (new LogListener($logger))(new WorkflowInterrupted($state));

        $this->assertSame([1, 2], array_column($logger->records[0]['context']['interrupts'], 'interruptId'));
    }

    public function test_retrieving_logs_filter_structure_without_values(): void
    {
        $logger = $this->recordingLogger();

        (new LogListener($logger))(new Retrieving(
            new UserMessage('question'),
            FilterGroup::allOf(
                Filter::eq('tenant', 'secret-tenant'),
                Filter::raw(MariaDBVectorStore::class, 'secret SQL fragment'),
            ),
        ));

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                [
                    'operator' => 'eq',
                    'field' => 'tenant',
                ],
                [
                    'operator' => 'raw',
                    'store' => MariaDBVectorStore::class,
                ],
            ],
        ], $logger->records[0]['context']['filters']);
    }
}
