<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\LogObserver;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\Observability\Retrieving;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\Tests\Observability\Stub\CustomTestEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowInterrupted;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\TestWith;
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

    public function test_interruption_logs_the_current_request(): void
    {
        $state = new WorkflowState();
        $state->setExecutionMetadata('thread', 'run', 2);
        $state->markAsSuspended((new ApprovalRequest('first'))->withId(1));

        $logger = $this->recordingLogger();
        (new LogListener($logger))(new WorkflowInterrupted($state));

        $this->assertSame(1, $logger->records[0]['context']['interrupt']['interruptId']);
        $this->assertSame('thread', $logger->records[0]['context']['workflowId']);
        $this->assertSame('run', $logger->records[0]['context']['runId']);
        $this->assertSame(2, $logger->records[0]['context']['executionAttempt']);
        $this->assertSame('suspended', $logger->records[0]['context']['status']);
    }

    #[TestWith([WorkflowStatus::Suspended, false])]
    #[TestWith([WorkflowStatus::Completed, false])]
    #[TestWith([WorkflowStatus::Failed, false])]
    #[TestWith([WorkflowStatus::Suspended, true])]
    public function test_terminal_logs_identify_the_run_attempt_and_outcome(WorkflowStatus $status, bool $legacy): void
    {
        $state = new WorkflowState(['value' => 42]);
        $state->setExecutionMetadata('thread', 'run', 3);
        match ($status) {
            WorkflowStatus::Suspended => $state->markAsSuspended((new ApprovalRequest('approval'))->withId(2)),
            WorkflowStatus::Completed => $state->clearInterrupt(),
            default => $state->markAsFailed(),
        };
        $logger = $this->recordingLogger();
        $event = new WorkflowEnd($state);
        if ($legacy) {
            (new LogObserver($logger))->onEvent($event->name(), $this, $event);
        } else {
            (new LogListener($logger))($event);
        }
        $this->assertSame([
            'workflowId' => 'thread',
            'runId' => 'run',
            'executionAttempt' => 3,
            'status' => $status->value,
            'state' => ['value' => 42],
        ], $logger->records[0]['context']);
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

    public function test_custom_event_logs_its_own_data(): void
    {
        $logger = $this->recordingLogger();

        (new LogListener($logger))(new CustomTestEvent('scored'));

        $this->assertSame('custom-test-event', $logger->records[0]['message']);
        $this->assertSame(['value' => 'scored'], $logger->records[0]['context']);
    }

    public function test_subclass_redacts_through_context(): void
    {
        $logger = $this->recordingLogger();
        $listener = new class ($logger) extends LogListener {
            protected function context(ObservabilityEvent $event): array
            {
                return $event instanceof InferenceStart ? [] : parent::context($event);
            }
        };

        $listener(new InferenceStart(new UserMessage('secret')));

        $this->assertSame([], $logger->records[0]['context']);
    }
}
