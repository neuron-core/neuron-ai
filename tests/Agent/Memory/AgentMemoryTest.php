<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
use NeuronAI\Agent\Events\StoreMemoryEvent;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\RecallMemoryNode;
use NeuronAI\Agent\Nodes\StoreMemoryNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\MemoryRecalled;
use NeuronAI\Observability\Events\MemoryRecalling;
use NeuronAI\Observability\Events\MemoryStored;
use NeuronAI\Observability\Events\MemoryStoring;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Stubs\StructuredOutput\User;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function iterator_to_array;
use function json_decode;
use function str_starts_with;
use function substr;
use function array_key_last;

class AgentMemoryTest extends TestCase
{
    public function test_agent_recalls_before_inference_and_remembers_each_completed_exchange(): void
    {
        $memory = new InspectableMemory([
            "User: My name is Taylor.\nAssistant: Nice to meet you, Taylor.",
        ]);
        $provider = new FakeAIProvider(
            new AssistantMessage('Welcome back.'),
            new AssistantMessage('You are Taylor.'),
        );
        $agent = Agent::make(threadId: 'thread-1');
        $agent->setAiProvider($provider)->setMemory($memory);

        $agent->chat(new UserMessage('Hello'));
        $agent->chat(new UserMessage('What is my name?'));

        $this->assertSame([
            'Hello',
            'What is my name?',
        ], $memory->recalls);
        $this->assertSame([
            ['thread-1', 'Hello', 'Welcome back.'],
            ['thread-1', 'What is my name?', 'You are Taylor.'],
        ], $memory->remembered);

        foreach ($provider->getRecorded() as $record) {
            $this->assertTrue($record->systemPrompt?->contains('My name is Taylor.') ?? false);
        }

        $this->assertInstanceOf(
            RecallMemoryNode::class,
            $agent->getEventNodeMap()[RecallMemoryEvent::class] ?? null,
        );
        $this->assertInstanceOf(
            StoreMemoryNode::class,
            $agent->getEventNodeMap()[StoreMemoryEvent::class] ?? null,
        );
    }

    public function test_agent_delegates_recall_scope_to_memory(): void
    {
        $memory = new InspectableMemory();
        $agent = Agent::make(threadId: 'current-thread')
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Response.')))
            ->setMemory($memory);

        $agent->chat(new UserMessage('Question'));

        $this->assertSame(['Question'], $memory->recalls);
        $this->assertSame([
            ['current-thread', 'Question', 'Response.'],
        ], $memory->remembered);
    }

    public function test_memory_nodes_emit_safe_observability_events(): void
    {
        $memory = new InspectableMemory(['Remembered context']);
        $events = [];
        $agent = new Agent(threadId: 'thread-1');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Response.')));
        $agent->setMemory($memory);

        foreach ([
            MemoryRecalling::class,
            MemoryRecalled::class,
            MemoryStoring::class,
            MemoryStored::class,
        ] as $eventClass) {
            $agent->subscribe($eventClass, static function (ObservabilityEvent $event) use (&$events): void {
                $events[] = $event;
            });
        }

        $agent->chat(new UserMessage('Question.'));

        $this->assertSame([
            MemoryRecalling::class,
            MemoryRecalled::class,
            MemoryStoring::class,
            MemoryStored::class,
        ], array_map(static fn (object $event): string => $event::class, $events));
        $this->assertInstanceOf(MemoryRecalling::class, $events[0]);
        $this->assertInstanceOf(MemoryRecalled::class, $events[1]);
        $this->assertSame(1, $events[1]->memoryCount);
        $this->assertInstanceOf(RecallMemoryNode::class, $events[0]->source);
        $this->assertInstanceOf(RecallMemoryNode::class, $events[1]->source);
        $this->assertInstanceOf(StoreMemoryNode::class, $events[2]->source);
        $this->assertInstanceOf(StoreMemoryNode::class, $events[3]->source);
    }

    public function test_failed_memory_recall_has_no_completion_observability_event(): void
    {
        $memory = new class () implements MemoryInterface {
            public function recall(string $query): array
            {
                throw new RuntimeException('Memory unavailable.');
            }

            public function remember(string $threadId, string $user, string $assistant): void
            {
            }

            public function forget(string $threadId): void
            {
            }
        };
        $events = [];
        $agent = new Agent(threadId: 'thread-1');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Response.')));
        $agent->setMemory($memory);

        foreach ([MemoryRecalling::class, MemoryRecalled::class, AgentError::class] as $eventClass) {
            $agent->subscribe($eventClass, static function (ObservabilityEvent $event) use (&$events): void {
                $events[] = $event;
            });
        }

        try {
            $agent->chat(new UserMessage('Question.'));
            $this->fail('The memory failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Memory unavailable.', $exception->getMessage());
        }

        $this->assertSame([
            MemoryRecalling::class,
            AgentError::class,
        ], array_map(static fn (object $event): string => $event::class, $events));
    }

    public function test_tool_loop_recalls_once_and_stores_the_completed_exchange(): void
    {
        $memory = new InspectableMemory();
        $tool = new MemoryLookupTool();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($tool->getName(), 'call-1'),
            ]),
            new AssistantMessage('The lookup is complete.'),
        );
        $agent = Agent::make(threadId: 'thread-1')
            ->setAiProvider($provider)
            ->setMemory($memory)
            ->addTool($tool);

        $agent->chat(new UserMessage('Run the lookup.'));

        $this->assertSame(['Run the lookup.'], $memory->recalls);
        $this->assertSame([
            ['thread-1', 'Run the lookup.', 'The lookup is complete.'],
        ], $memory->remembered);
    }

    public function test_streaming_stores_the_completed_exchange(): void
    {
        $memory = new InspectableMemory();
        $agent = Agent::make(threadId: 'thread-1')
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Streamed answer.')))
            ->setMemory($memory);

        $stream = $agent->stream(new UserMessage('Stream this.'));
        iterator_to_array($stream);

        $this->assertSame([
            ['thread-1', 'Stream this.', 'Streamed answer.'],
        ], $memory->remembered);
    }

    public function test_streaming_exposes_memory_steps_through_the_adapter(): void
    {
        $memory = new InspectableMemory(['Remembered context']);
        $agent = Agent::make(threadId: 'thread-1')
            ->setStreamAdapter(new VercelAIAdapter());
        $agent
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer.')))
            ->setMemory($memory);

        $lines = iterator_to_array($agent->stream(new UserMessage('Question.')));
        $events = [];

        foreach ($lines as $line) {
            if (!str_starts_with($line, 'data: {')) {
                continue;
            }

            $event = json_decode(substr($line, 6, -2), true);
            $this->assertIsArray($event);
            $events[] = $event;
        }

        $stepEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['type'] === 'data-workflow-step',
        ));

        $this->assertSame([
            ['name' => 'memory.recall', 'status' => 'started'],
            ['name' => 'memory.recall', 'status' => 'finished', 'metadata' => ['memories' => 1]],
            ['name' => 'memory.store', 'status' => 'started'],
            ['name' => 'memory.store', 'status' => 'finished'],
        ], array_column($stepEvents, 'data'));
        $this->assertSame('data-workflow-step', $events[0]['type']);
        $this->assertContains('start', array_column($events, 'type'));
        $this->assertContains('text-delta', array_column($events, 'type'));
        $this->assertSame('finish', $events[array_key_last($events)]['type']);
    }

    public function test_structured_output_stores_the_completed_exchange(): void
    {
        $memory = new InspectableMemory();
        $agent = Agent::make(threadId: 'thread-1')
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name":"Taylor"}')))
            ->setMemory($memory);

        $agent->structured(new UserMessage('Create a user.'), User::class);

        $this->assertSame([
            ['thread-1', 'Create a user.', '{"name":"Taylor"}'],
        ], $memory->remembered);
    }

    public function test_structured_retry_stores_the_original_request(): void
    {
        $memory = new InspectableMemory();
        $agent = Agent::make(threadId: 'thread-1')
            ->setAiProvider(new FakeAIProvider(
                new AssistantMessage('Invalid output.'),
                new AssistantMessage('{"name":"Taylor"}'),
            ))
            ->setMemory($memory);

        $agent->structured(new UserMessage('Create a user.'), User::class);

        $this->assertSame([
            ['thread-1', 'Create a user.', '{"name":"Taylor"}'],
        ], $memory->remembered);
    }

    public function test_approved_tool_resume_stores_the_exchange_without_recalling_again(): void
    {
        $memory = new InspectableMemory();
        $history = new InMemoryChatHistory('thread-1');
        $persistence = new InMemoryPersistence();
        $tool = (new MemoryLookupTool())->requireApproval();

        $agent = Agent::make(threadId: 'thread-1');
        $agent->setAiProvider(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make($tool->getName(), 'call-1'),
        ])));
        $agent->setChatHistory($history);
        $agent->setPersistence($persistence);
        $agent->setMemory($memory);
        $agent->addTool($tool);

        $interrupted = $agent->chat(new UserMessage('Run the approved lookup.'));
        $this->assertTrue($interrupted->isInterrupted());

        $resumingAgent = Agent::make(threadId: 'thread-1');
        $resumingAgent->setAiProvider(new FakeAIProvider(new AssistantMessage('Approved lookup complete.')));
        $resumingAgent->setChatHistory($history);
        $resumingAgent->setPersistence($persistence);
        $resumingAgent->setMemory($memory);
        $resumingAgent->addTool($tool);
        $resumed = $resumingAgent->resume([ResumeInput::event(1, ['call-1' => 'approve'])]);

        $this->assertFalse($resumed->isInterrupted());
        $this->assertSame(['Run the approved lookup.'], $memory->recalls);
        $this->assertSame([
            ['thread-1', 'Run the approved lookup.', 'Approved lookup complete.'],
        ], $memory->remembered);
    }

    public function test_middleware_can_customize_the_exchange_before_it_is_stored(): void
    {
        $memory = new InspectableMemory();
        $agent = Agent::make(threadId: 'thread-1');
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Private answer.')))
            ->setMemory($memory);
        $agent->addMiddleware(StoreMemoryNode::class, new class () extends AgentMiddleware {
            protected function beforeAgentNode(
                AgentNodeInterface $node,
                Event $event,
                AgentState $state,
            ): void {
                if ($event instanceof StoreMemoryEvent) {
                    foreach ($event->messages as $index => $message) {
                        if ($message instanceof UserMessage) {
                            $event->messages[$index] = new UserMessage('[redacted]');
                            break;
                        }
                    }
                }
            }
        });

        $agent->chat(new UserMessage('Private question.'));

        $this->assertSame([
            ['thread-1', '[redacted]', 'Private answer.'],
        ], $memory->remembered);
    }

    public function test_remember_side_effect_is_memoized_across_node_replay(): void
    {
        $memory = new InspectableMemory();
        $history = new InMemoryChatHistory('thread-1');
        $persistence = new InMemoryPersistence();
        $event = new StoreMemoryEvent([
            new UserMessage('Question'),
            new AssistantMessage('Answer'),
        ]);

        $first = new StoreMemoryNode($memory, $history);
        $first->setWorkflowContext(new NodeContext(
            new AgentState(),
            $event,
            memoizer: new StepMemoizer(
                $persistence,
                new PhpSerializer(),
                'thread-1',
                'memory-step',
            ),
        ));
        iterator_to_array($first($event, new AgentState()));

        $replayed = new StoreMemoryNode($memory, $history);
        $replayed->setWorkflowContext(new NodeContext(
            new AgentState(),
            $event,
            memoizer: new StepMemoizer(
                $persistence,
                new PhpSerializer(),
                'thread-1',
                'memory-step',
            ),
        ));
        iterator_to_array($replayed($event, new AgentState()));

        $this->assertSame([
            ['thread-1', 'Question', 'Answer'],
        ], $memory->remembered);
    }

    public function test_recall_side_effect_is_memoized_across_node_replay(): void
    {
        $memory = new InspectableMemory(['Remembered context']);
        $history = new InMemoryChatHistory('thread-1');
        $persistence = new InMemoryPersistence();

        $firstEvent = new RecallMemoryEvent($this->inference('Question'));
        $first = new RecallMemoryNode($memory, $history);
        $first->setWorkflowContext(new NodeContext(
            new AgentState(),
            $firstEvent,
            memoizer: new StepMemoizer(
                $persistence,
                new PhpSerializer(),
                'thread-1',
                'memory-recall-step',
            ),
        ));
        $firstStream = $first($firstEvent, new AgentState());
        $firstEvents = iterator_to_array($firstStream);
        $firstResult = $firstStream->getReturn();

        $replayedEvent = new RecallMemoryEvent($this->inference('Question'));
        $replayed = new RecallMemoryNode($memory, $history);
        $replayed->setWorkflowContext(new NodeContext(
            new AgentState(),
            $replayedEvent,
            memoizer: new StepMemoizer(
                $persistence,
                new PhpSerializer(),
                'thread-1',
                'memory-recall-step',
            ),
        ));
        $replayedStream = $replayed($replayedEvent, new AgentState());
        $replayedEvents = iterator_to_array($replayedStream);
        $replayedResult = $replayedStream->getReturn();

        $this->assertSame(['Question'], $memory->recalls);
        $this->assertContainsOnlyInstancesOf(StepStartedStreamEvent::class, [$firstEvents[0], $replayedEvents[0]]);
        $this->assertContainsOnlyInstancesOf(StepFinishedStreamEvent::class, [$firstEvents[1], $replayedEvents[1]]);
        $this->assertTrue($firstResult->instructions->contains('Remembered context'));
        $this->assertTrue($replayedResult->instructions->contains('Remembered context'));
    }

    protected function inference(string $query): AIInferenceEvent
    {
        $event = new AIInferenceEvent(new SystemMessage('Instructions'), []);
        $event->setMessages(new UserMessage($query));

        return $event;
    }
}

class InspectableMemory implements MemoryInterface
{
    /** @var string[] */
    public array $recalls = [];

    /** @var array<int, array{string, string, string}> */
    public array $remembered = [];

    /** @param string[] $recalled */
    public function __construct(protected array $recalled = [])
    {
    }

    public function recall(string $query): array
    {
        $this->recalls[] = $query;

        return $this->recalled;
    }

    public function remember(string $threadId, string $user, string $assistant): void
    {
        $this->remembered[] = [$threadId, $user, $assistant];
    }

    public function forget(string $threadId): void
    {
    }
}

class MemoryLookupTool extends Tool
{
    protected string $name = 'memory_lookup';

    public function __invoke(): string
    {
        return 'Lookup result';
    }
}
