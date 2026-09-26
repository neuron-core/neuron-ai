<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Agent\Observability\Extracted;
use NeuronAI\Agent\Observability\InferenceStart;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Agent\Observability\MessageSaved;
use NeuronAI\Agent\Observability\SchemaGenerated;
use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Agent\Observability\Validated;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\StructuredOutput\Stub\Person;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;
use Spatie\Fork\Fork;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function extension_loaded;
use function json_encode;
use function serialize;
use function str_starts_with;

/**
 * The agent's domain events are a public contract for loggers, tracers and
 * metrics: their order and payloads are pinned here.
 */
class AgentObservabilityTest extends TestCase
{
    public const SECRET = 'sk-live-0123456789-do-not-leak';

    /** @var ObservabilityEvent[] */
    protected array $events = [];

    protected function observe(Agent $agent): Agent
    {
        $agent->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
            if (str_starts_with($event::class, 'NeuronAI\\Agent\\Observability\\')) {
                $this->events[] = $event;
            }
        });

        return $agent;
    }

    /**
     * @return string[]
     */
    protected function eventNames(): array
    {
        return array_map(static fn (ObservabilityEvent $event): string => $event->name(), $this->events);
    }

    /**
     * @template T of ObservabilityEvent
     * @param class-string<T> $class
     * @return T[]
     */
    protected function eventsOf(string $class): array
    {
        return array_values(array_filter($this->events, static fn (ObservabilityEvent $event): bool => $event instanceof $class));
    }

    public function test_a_tool_loop_emits_the_agent_events_in_order(): void
    {
        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'php'])]),
                new AssistantMessage('Done'),
            ))
            ->addTool(new SearchTool());

        $this->observe($agent)->chat(new UserMessage('Find php'));

        $this->assertSame([
            // First inference commits the user message after the provider call.
            'inference-start', 'inference-stop', 'message-saving', 'message-saved',
            'tool-calling', 'tool-called',
            // Second inference commits the call/result pair, then the answer.
            'inference-start', 'inference-stop',
            'message-saving', 'message-saved', 'message-saving', 'message-saved', 'message-saving', 'message-saved',
        ], $this->eventNames());

        $saved = array_map(static fn (MessageSaved $event): string => $event->message::class, $this->eventsOf(MessageSaved::class));
        $this->assertSame([UserMessage::class, ToolCallMessage::class, ToolResultMessage::class, AssistantMessage::class], $saved);
    }

    public function test_tool_events_report_the_call_before_and_after_execution(): void
    {
        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'php'])]),
                new AssistantMessage('Done'),
            ))
            ->addTool(new SearchTool());

        $this->observe($agent)->chat(new UserMessage('Find php'));

        [$calling] = $this->eventsOf(ToolCalling::class);
        [$called] = $this->eventsOf(ToolCalled::class);

        $this->assertInstanceOf(ToolNode::class, $calling->source);
        $this->assertFalse($calling->fork);
        $this->assertSame('call_1', $calling->toArray()['tool']['callId']);
        $this->assertSame(['query' => 'php'], $calling->toArray()['tool']['inputs']);
        $this->assertSame('Results for: php', $called->toArray()['tool']['result']);
        $this->assertSame('tool-called', $called->name());
    }

    public function test_inference_events_report_the_request_tail_and_the_response(): void
    {
        $agent = Agent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello!')));

        $this->observe($agent)->chat(new UserMessage('Hi'));

        [$start] = $this->eventsOf(InferenceStart::class);
        [$stop] = $this->eventsOf(InferenceStop::class);

        $this->assertInstanceOf(ChatNode::class, $start->source);
        $this->assertSame('Hi', $start->message->getContent());
        $this->assertSame($start->message, $stop->message);
        $this->assertSame('Hello!', $stop->response->message()->getContent());
        $this->assertSame('user', $stop->toArray()['message']['role']);
        $this->assertSame('assistant', $stop->toArray()['response']['role']);
    }

    public function test_structured_output_events_report_each_attempt_and_its_violations(): void
    {
        $invalid = '{"firstName":"","lastName":"Doe","address":{"street":"Main","city":"Rome","zip":"00100"},"tags":[]}';
        $valid = '{"firstName":"Jane","lastName":"Doe","address":{"street":"Main","city":"Rome","zip":"00100"},"tags":[]}';
        $agent = Agent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage($invalid), new AssistantMessage($valid)));

        $this->observe($agent)->structured(new UserMessage('Generate a person'), Person::class);

        $attempt = [
            'inference-start', 'inference-stop', 'message-saving', 'message-saved', 'message-saving', 'message-saved',
            'structured-extracting', 'structured-extracted', 'structured-deserializing', 'structured-deserialized',
            'structured-validating', 'structured-validated',
        ];
        $this->assertSame(['schema-generation', 'schema-generated', ...$attempt, ...$attempt], $this->eventNames());

        [$schema] = $this->eventsOf(SchemaGenerated::class);
        $this->assertSame(['class' => Person::class, 'schema' => $schema->schema], $schema->toArray());
        $this->assertSame('object', $schema->schema['type']);

        [$rejected, $accepted] = $this->eventsOf(Validated::class);
        $this->assertSame(['class' => Person::class, 'json' => $invalid, 'violations' => ['firstName cannot be blank']], $rejected->toArray());
        $this->assertSame(['class' => Person::class, 'json' => $valid, 'violations' => []], $accepted->toArray());

        [, $extracted] = $this->eventsOf(Extracted::class);
        $this->assertSame($valid, $extracted->toArray()['json']);
        $this->assertSame($schema->schema, $extracted->toArray()['schema']);
    }

    public function test_structured_output_event_payloads(): void
    {
        $json = '{"name":"Alice"}';
        $agent = Agent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage($json)));

        $this->observe($agent)->structured(new UserMessage('Generate a user'), User::class);

        $payloads = [];
        foreach ($this->events as $event) {
            if (str_starts_with($event->name(), 'structured-') || str_starts_with($event->name(), 'schema-generation')) {
                $payloads[$event->name()] = $event->toArray();
            }
        }

        $this->assertSame(['class' => User::class], $payloads['schema-generation']);
        $this->assertSame('assistant', $payloads['structured-extracting']['message']['role']);
        $this->assertSame(['class' => User::class], $payloads['structured-deserializing']);
        $this->assertSame(['class' => User::class], $payloads['structured-deserialized']);
        $this->assertSame(['class' => User::class, 'json' => $json], $payloads['structured-validating']);
        $this->assertSame(['class' => User::class, 'json' => $json, 'violations' => []], $payloads['structured-validated']);
    }

    public function test_a_rejected_tool_emits_no_execution_events(): void
    {
        $persistence = new InMemoryPersistence();
        $store = new InMemoryMessageStore();
        $agent = fn (): Agent => Agent::make(workflowId: 'observed-rejection')
            ->setPersistence($persistence)
            ->setMessageStore($store)
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'secret'])]),
                new AssistantMessage('Understood'),
            ))
            ->addTool((new SearchTool())->requireApproval());

        $agent()->chat(new UserMessage('Search secret'));
        $this->observe($agent())->submitApprovalDecisions(['call_1' => 'reject'])->run();

        $this->assertSame([], $this->eventsOf(ToolCalling::class));
        $this->assertSame([], $this->eventsOf(ToolCalled::class));
    }

    public function test_a_deferred_call_refused_by_its_limit_reports_the_handled_result(): void
    {
        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [new ToolCall('browser', 'call_1', deferred: true)]),
                new AssistantMessage('Done'),
            ))
            ->addTool((new FrontendTool('browser'))->setMaxRuns(0))
            ->toolErrorHandler(fn (Throwable $error): string => 'Limit reached');

        $this->observe($agent)->chat(new UserMessage('Open the page'));

        // Nothing was handed off to the client, yet the call was settled.
        $this->assertSame([], $this->eventsOf(ToolCalling::class));
        [$called] = $this->eventsOf(ToolCalled::class);
        $this->assertSame('call_1', $called->toArray()['tool']['callId']);
        $this->assertSame('Limit reached', $called->toArray()['tool']['result']);
    }

    public function test_an_externally_submitted_result_is_reported_once_it_arrives(): void
    {
        $persistence = new InMemoryPersistence();
        $store = new InMemoryMessageStore();
        $agent = fn (): Agent => Agent::make(workflowId: 'observed-frontend-result')
            ->setPersistence($persistence)
            ->setMessageStore($store)
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [new ToolCall('browser', 'call_1', deferred: true)]),
                new AssistantMessage('Done'),
            ))
            ->addTool(new FrontendTool('browser'));

        $this->observe($agent())->chat(new UserMessage('Open the page'));
        $this->assertSame([], $this->eventsOf(ToolCalled::class));

        $this->observe($agent())->submitToolResults(['call_1' => ['result' => 'Page loaded']])->run();

        $called = $this->eventsOf(ToolCalled::class);
        $this->assertCount(1, $called);
        $this->assertSame('call_1', $called[0]->toArray()['tool']['callId']);
        $this->assertSame('Page loaded', $called[0]->toArray()['tool']['result']);
    }

    public function test_parallel_tool_calls_are_flagged_as_forked(): void
    {
        if (!extension_loaded('pcntl') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Concurrent tool execution requires pcntl and spatie/fork.');
        }

        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    ToolCall::make('search', 'call_1', ['query' => 'one']),
                    ToolCall::make('search', 'call_2', ['query' => 'two']),
                ]),
                new AssistantMessage('Done'),
            ))
            ->addTool(new SearchTool())
            ->parallelToolCalls();

        $this->observe($agent)->chat(new UserMessage('Search twice'));

        $this->assertSame([true, true], array_map(static fn (ToolCalling $event): bool => $event->fork, $this->eventsOf(ToolCalling::class)));
        $this->assertSame(
            ['Results for: one', 'Results for: two'],
            array_map(static fn (ToolCalled $event): mixed => $event->toArray()['tool']['result'], $this->eventsOf(ToolCalled::class))
        );
    }

    public function test_provider_credentials_never_reach_events_or_persisted_state(): void
    {
        $provider = new class (new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'php'])])) extends FakeAIProvider {
            protected string $key = AgentObservabilityTest::SECRET;
        };
        $persistence = new InMemoryPersistence();
        $agent = Agent::make(workflowId: 'credentials')
            ->setPersistence($persistence)
            ->setAiProvider($provider)
            ->addTool((new SearchTool())->requireApproval());

        $state = $this->observe($agent)->chat(new UserMessage('Find php'));

        $this->assertTrue($state->isInterrupted(), 'The suspended run keeps its state persisted');
        $this->assertNotSame([], $this->events);
        foreach ($this->events as $event) {
            $this->assertStringNotContainsString(self::SECRET, (string) json_encode($event->toArray()), $event->name());
        }
        $this->assertStringNotContainsString(self::SECRET, serialize($persistence));
        $this->assertStringNotContainsString(self::SECRET, serialize($state));
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($state->getInterruptRequest()));
    }
}
