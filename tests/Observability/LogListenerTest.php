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
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Workflow;
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

    public function testLogListenerLogsEveryEventWithSerializedContext(): void
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

    public function testDeprecatedLogObserverStillLogsThroughObserve(): void
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

    public function testMemoryEventsLogOnlySafeMonitoringContext(): void
    {
        $logger = $this->recordingLogger();
        $listener = new LogListener($logger);

        $listener(new MemoryRecalling(3));
        $listener(new MemoryRecalled(3, 5));
        $listener(new MemoryStoring());
        $listener(new MemoryStored());

        $this->assertSame([
            [
                'level' => 'info',
                'message' => 'memory-recalling',
                'context' => ['thread-count' => 3],
            ],
            [
                'level' => 'info',
                'message' => 'memory-recalled',
                'context' => ['thread-count' => 3, 'memory-count' => 5],
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

    public function testRetrievingLogsFilterStructureWithoutValues(): void
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
