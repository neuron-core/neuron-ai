<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use LogicException;
use NeuronAI\Agent\Adapters\AgentChunkAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeChannel;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class AgentManagedExecutionTest extends TestCase
{
    public function test_explicit_address_resolves_thread_without_constructing_services(): void
    {
        $agent = new class (workflowId: 'thread') extends Agent {
            protected function messageStore(): \NeuronAI\Chat\History\MessageStoreInterface
            {
                throw new LogicException('The message store must remain lazy.');
            }
        };
        self::assertSame('thread', $agent->getWorkflowId());
        self::assertSame('thread', $agent->getThreadId());
        self::assertNull($agent->inspect()?->runId);
    }

    public function test_inert_stream_start_and_new_turn_use_reserved_generations_and_one_history(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First answer'), new AssistantMessage('Second answer'));
        $messageStore = new InMemoryMessageStore();
        $store = new InMemoryPersistence();
        $channel = new FakeChannel();
        $make = fn (): Agent => Agent::make(workflowId: 'thread')->setPersistence($store)->retainCompletionUntilAcknowledged();
        $make = fn (): Agent => Agent::make(workflowId: 'thread')->setPersistence($store)->retainCompletionUntilAcknowledged()
            ->setAiProvider($provider)->setMessageStore($messageStore)->setStreamAdapter(fn (): AgentChunkAdapter => new AgentChunkAdapter())->setChannel(fn (): StreamingChannelInterface => $channel);
        $first = $make();
        $request = ExecutionRequest::start(new AgentStartEvent([new UserMessage('One')], new AgentRunOptions(stream: true)), 'first');
        $provider->assertCallCount(0);
        self::assertNull($first->inspect());
        $result = $first->run($request);
        self::assertInstanceOf(AgentState::class, $result);
        self::assertSame('first', $result->getRunId());
        self::assertSame('First answer', $result->getMessage()->getContent());
        self::assertNotEmpty($channel->getSent());
        $first->acknowledge('first');
        $second = $make()->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Two')]), 'second'));
        self::assertSame('second', $second->getRunId());
        self::assertFalse($second->request->options->stream);
        self::assertCount(4, $messageStore->loadActive('thread'));
        $provider->assertCallCount(2);
    }

    public function test_fresh_instance_restores_approval_intent_before_runtime_tool_and_channel_setup(): void
    {
        $tool = (new SearchTool())->requireApproval();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make($tool->getName(), 'call_1', ['query' => 'PHP'])]),
            new AssistantMessage('Done'),
        );
        $store = new InMemoryPersistence();
        $messageStore = new InMemoryMessageStore();
        $channel = new FakeChannel();
        $make = fn (): Agent => Agent::make(workflowId: 'thread')->setPersistence($store)->retainCompletionUntilAcknowledged();
        $make = fn (): Agent => Agent::make(workflowId: 'thread')->setPersistence($store)->retainCompletionUntilAcknowledged()
            ->setAiProvider($provider)->setTools([$tool])->setMessageStore($messageStore)->setChannel(fn (): StreamingChannelInterface => $channel)
            ->setStreamAdapter(fn (): AgentChunkAdapter => new AgentChunkAdapter());
        $first = $make()->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Question')], new AgentRunOptions(stream: true)), 'reserved'));
        self::assertTrue($first->isInterrupted());
        $resumed = $make()->setStartEvent(new AgentStartEvent([new UserMessage('Wrong local intent')]));
        $record = new \NeuronAI\Tests\Support\ExecutionRecorder($resumed);
        $reply = $resumed->submitApprovalDecisions(['call_1' => 'approve'])->run();
        self::assertSame('reserved', $record->context->runId);
        self::assertSame('Question', $record->context->startEvent()->messages[0]->getContent());
        self::assertTrue($record->context->startEvent()->options->stream);
        self::assertSame('stream', $provider->getRecorded()[1]->method);
        self::assertSame('reserved', $reply->getRunId());
        self::assertSame(2, $reply->getExecutionAttempt());
        self::assertSame(WorkflowStatus::Completed, $reply->getStatus());
        $provider->assertCallCount(2);
    }

    public function test_output_setup_can_replace_the_message_store_without_redirecting_the_active_run(): void
    {
        $original = new InMemoryMessageStore();
        $next = new InMemoryMessageStore();
        $agent = Agent::make(workflowId: 'thread')->setMessageStore($original)
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Done')));
        $agent->setStreamAdapter(function () use ($agent, $next): AgentChunkAdapter {
            $agent->setMessageStore($next);
            return new AgentChunkAdapter();
        });

        $state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hello')]), 'reserved'));

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame('thread', $state->getWorkflowId());
        self::assertSame('reserved', $state->getRunId());
        self::assertSame('thread', $agent->getWorkflowId());
        self::assertCount(2, $original->loadActive('thread'));
        self::assertSame([], $next->loadAll('thread'));
    }

    public function test_listeners_cannot_mutate_persisted_inference_intent(): void
    {
        $agent = Agent::make(workflowId: 'thread')->setAiProvider(new FakeAIProvider(new AssistantMessage('Done')));
        $agent->subscribe(\NeuronAI\Workflow\Observability\WorkflowStart::class, static function (\NeuronAI\Workflow\Observability\WorkflowStart $event): void {
            $event->execution->startEvent()->options->stream = true;
            self::assertFalse($event->execution->startEvent()->options->stream);
        });
        $state = $agent->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hello')]), 'reserved'));
        self::assertFalse($state->request->options->stream);
    }
    public function test_staged_structured_output_runs_through_the_state_terminal(): void
    {
        $agent = Agent::make(workflowId: 'thread')->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name":"Ada"}')));
        $result = $agent->run(ExecutionRequest::start(new AgentStartEvent(
            [new UserMessage('Name?')],
            new AgentRunOptions(outputClass: \NeuronAI\Tests\StructuredOutput\Stub\User::class)
        ), 'reserved'));
        self::assertInstanceOf(AgentState::class, $result);
        self::assertInstanceOf(\NeuronAI\Tests\StructuredOutput\Stub\User::class, $result->get('structured_output'));
    }

    public function test_saved_outcome_never_opens_runtime_tools_or_output_resources(): void
    {
        $store = new InMemoryPersistence();
        Agent::make(workflowId: 'thread')->setPersistence($store)->retainCompletionUntilAcknowledged()
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Saved')))
            ->run(ExecutionRequest::start(new AgentStartEvent([new UserMessage('Hello')]), 'reserved'));
        $agent = Agent::make(workflowId: 'thread')->setPersistence($store)
            ->setStreamAdapter(function (): never {
                self::fail('Saved outcomes must not resolve resources.');
            });
        $result = $agent->run(ExecutionRequest::resume());
        self::assertSame('Saved', $result->getMessage()->getContent());
    }

    public function test_two_lazy_stream_requests_keep_their_own_messages(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('One answer'), new AssistantMessage('Two answer'));
        $messageStore = new InMemoryMessageStore();
        $agent = Agent::make(workflowId: 'thread')->setAiProvider($provider)->setMessageStore($messageStore);
        $first = $agent->stream(new UserMessage('One'));
        $second = $agent->stream(new UserMessage('Two'));
        $provider->assertCallCount(0);
        iterator_to_array($first);
        self::assertSame('One', $messageStore->loadActive('thread')[0]->getContent());
        $firstRunId = $first->getReturn()->getRunId();
        iterator_to_array($second);
        self::assertSame('Two', $messageStore->loadActive('thread')[2]->getContent());
        self::assertNotSame($firstRunId, $second->getReturn()->getRunId());
    }

}
