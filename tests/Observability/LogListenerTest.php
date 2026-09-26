<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
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
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Observability\WorkflowInterrupted;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use stdClass;
use Stringable;

use function array_column;
use function array_unique;
use function array_values;
use function json_encode;

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

        $this->assertSame([
            'workflow-start',
            'workflow-node-start',
            'workflow-node-end',
            'workflow-node-start',
            'workflow-node-end',
            'workflow-node-start',
            'workflow-node-end',
            'workflow-end',
        ], array_column($logger->records, 'message'));
        $this->assertSame([LogLevel::INFO], array_values(array_unique(array_column($logger->records, 'level'))));
        $this->assertSame(['node' => NodeOne::class], $logger->records[1]['context']);
        $this->assertSame(['node' => NodeOne::class], $logger->records[2]['context']);
    }

    public function test_log_listener_uses_the_configured_level(): void
    {
        $logger = $this->recordingLogger();

        (new LogListener($logger, LogLevel::DEBUG))(new CustomTestEvent('value'));

        $this->assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
    }

    public function test_deprecated_log_observer_still_logs_through_observe(): void
    {
        $logger = $this->recordingLogger();

        Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->observe(new LogObserver($logger))
            ->run();

        $listenerLogger = $this->recordingLogger();
        Workflow::make()
            ->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
            ->subscribe(ObservabilityEvent::class, new LogListener($listenerLogger))
            ->run();

        $this->assertSame(array_column($listenerLogger->records, 'message'), array_column($logger->records, 'message'));
        $this->assertSame(['node' => NodeOne::class], $logger->records[1]['context']);
    }

    /**
     * @return iterable<string, array{mixed, array<mixed>}>
     */
    public static function legacyPayloads(): iterable
    {
        yield 'array' => [['key' => 'value'], ['key' => 'value']];
        yield 'string' => ['text', ['data' => 'text']];
        yield 'zero' => [0, ['data' => 0]];
        yield 'false' => [false, ['data' => false]];
        yield 'null' => [null, []];
    }

    /**
     * @param array<mixed> $expected
     */
    #[DataProvider('legacyPayloads')]
    public function test_log_observer_logs_legacy_payloads_as_context(mixed $data, array $expected): void
    {
        $logger = $this->recordingLogger();

        (new LogObserver($logger))->onEvent('legacy-event', $this, $data);

        $this->assertSame([['level' => LogLevel::INFO, 'message' => 'legacy-event', 'context' => $expected]], $logger->records);
    }

    public function test_log_observer_never_dumps_arbitrary_objects(): void
    {
        $payload = new stdClass();
        $payload->apiKey = 'sk-secret';
        $logger = $this->recordingLogger();

        (new LogObserver($logger))->onEvent('legacy-event', $this, $payload);

        $this->assertSame([], $logger->records[0]['context']);
    }

    public function test_error_logs_only_the_message_not_the_failed_request(): void
    {
        $request = new HttpRequest(HttpMethod::POST, 'https://api.example.com/v1/chat', [
            'Authorization' => 'Bearer sk-secret',
            'x-api-key' => 'sk-secret',
        ], '{"prompt":"hello"}');
        $exception = HttpException::statusError($request, new HttpResponse(401, '{"error":"unauthorized"}'));
        $logger = $this->recordingLogger();

        (new LogListener($logger))(new WorkflowError($exception));

        $this->assertSame('error', $logger->records[0]['message']);
        $this->assertSame(['error' => $exception->getMessage()], $logger->records[0]['context']);
        $this->assertStringNotContainsString('sk-secret', (string) json_encode($logger->records));
    }

    public function test_context_is_handed_to_the_logger_without_serializing_it(): void
    {
        $callback = static fn (): string => 'not serializable';
        $state = new WorkflowState(['callback' => $callback]);
        $state->setExecutionMetadata('thread', 'run', 1);
        $logger = $this->recordingLogger();

        (new LogListener($logger))(new WorkflowEnd($state));

        $this->assertSame($callback, $logger->records[0]['context']['state']['callback']);
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
