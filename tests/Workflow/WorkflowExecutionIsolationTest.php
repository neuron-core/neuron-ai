<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function iterator_to_array;
use function serialize;

class WorkflowExecutionIsolationTest extends TestCase
{
    public function test_reused_definition_keeps_results_state_seed_and_node_prototype_independent(): void
    {
        $seed = new WorkflowState(['nested' => (object) ['count' => 0]]);
        $node = new class () extends Node {
            public int $calls = 0;
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $state->get('nested')->count++;
                $state->set('calls', ++$this->calls);
                return new StopEvent();
            }
        };
        $workflow = Workflow::make(state: $seed)->addNode($node);
        $first = $workflow->run();
        $firstId = $first->getRunId();
        $second = $workflow->run();
        $second->get('nested')->count = 99;
        self::assertSame(1, $first->get('nested')->count);
        self::assertSame(0, $seed->get('nested')->count);
        self::assertSame(0, $node->calls);
        self::assertSame(1, $second->get('calls'));
        self::assertSame($firstId, $first->getRunId());
        self::assertNotSame($firstId, $second->getRunId());
        self::assertSame($first->getWorkflowId(), $second->getWorkflowId());
        self::assertSame($first->getWorkflowId(), $workflow->getWorkflowId());
    }

    public function test_lazy_agent_input_is_captured_before_the_callers_objects_change(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'));
        $agent = Agent::make(workflowId: 'conversation')->setAiProvider($provider);
        $message = new UserMessage('Original');
        $metadata = (object) ['value' => 'original'];
        $message->addMetadata('nested', ['object' => $metadata]);
        $first = $agent->stream($message);
        $message->setContents('Changed');
        $metadata->value = 'changed';
        $second = $agent->stream(new UserMessage('Next'));
        $provider->assertCallCount(0);
        iterator_to_array($first);
        $firstState = $first->getReturn();
        $firstId = $firstState->getRunId();
        iterator_to_array($second);
        self::assertSame('Original', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame('original', $provider->getRecorded()[0]->messages[0]->getMetadata('nested')['object']->value);
        self::assertSame('One', $firstState->getMessage()->getContent());
        self::assertSame($firstId, $firstState->getRunId());
        self::assertNotSame($firstId, $second->getReturn()->getRunId());
        self::assertCount(4, $agent->getChatHistory()->getMessages());
    }

    public function test_request_accessors_return_detached_input_and_response_values(): void
    {
        $event = new AgentStartEvent([new UserMessage('Original')]);
        $request = ExecutionRequest::start($event);
        $event->options->stream = true;
        $request->event()->messages[0]->setContents('Changed');
        self::assertFalse($request->event()->options->stream);
        self::assertSame('Original', $request->event()->messages[0]->getContent());
        $answer = (object) ['value' => 'accepted'];
        $resume = ExecutionRequest::resume(['answer' => $answer]);
        $answer->value = 'changed';
        $resume->payload()['answer']->value = 'changed again';
        self::assertSame('accepted', $resume->payload()['answer']->value);
    }

    public function test_generated_identity_supports_continuation_and_cleanup_without_repeating_the_address(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()])
            ->retainCompletionUntilAcknowledged();
        $first = $workflow->run();
        self::assertSame($first->getWorkflowId(), $workflow->getWorkflowId());
        self::assertSame($first->getRunId(), $workflow->inspect()->runId);
        $completed = $workflow->run(ExecutionRequest::resume([], $first->getRunId(), $first->getExecutionAttempt()));
        self::assertSame($first->getRunId(), $completed->getRunId());
        self::assertTrue($first->isInterrupted());
        self::assertFalse($completed->isInterrupted());
        $workflow->acknowledge($completed->getRunId());
        self::assertNull($workflow->inspect());
    }

    public function test_rebinding_a_workflow_is_rejected_before_persistence(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = KeyedWorkflow::make('bound')->setPersistence($persistence);
        $before = serialize($persistence);
        try {
            $workflow->setWorkflowId('different');
            self::fail('Expected conflicting address.');
        } catch (WorkflowException) {
            self::assertSame($before, serialize($persistence));
        }
    }

    public function test_graph_export_never_opens_output_resources_or_claims_a_run(): void
    {
        $workflow = KeyedWorkflow::make('preview')
            ->setChannel(function (): never {
                self::fail('Graph export cannot open transports.');
            });
        self::assertNotEmpty($workflow->export());
        self::assertNull($workflow->inspect());
    }
    public function test_setup_failure_reports_authoritative_metadata_without_a_live_runtime(): void
    {
        $workflow = KeyedWorkflow::make('setup-failure');
        $ends = [];
        $workflow->subscribe(\NeuronAI\Observability\Events\WorkflowEnd::class, function ($event) use (&$ends): void {
            $ends[] = $event;
        });
        $lateEnds = [];
        $workflow->setChannel(function () use ($workflow, &$lateEnds): never {
            $workflow->subscribe(\NeuronAI\Observability\Events\WorkflowEnd::class, function ($event) use (&$lateEnds): void {
                $lateEnds[] = $event;
            });
            throw new RuntimeException('Factory failed');
        });
        try {
            $workflow->run(ExecutionRequest::start(runId: 'reserved'));
            self::fail('Expected the resource factory to fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Factory failed', $error->getMessage());
        }
        self::assertCount(1, $ends);
        self::assertSame([], $lateEnds);
        self::assertSame($workflow, $ends[0]->source);
        self::assertSame('reserved', $ends[0]->execution->runId);
        self::assertSame(1, $ends[0]->execution->executionAttempt);
        self::assertSame(\NeuronAI\Workflow\WorkflowStatus::Failed, $ends[0]->state->getStatus());
        self::assertSame(\NeuronAI\Workflow\WorkflowStatus::Failed, $workflow->inspect()->status);
    }

    public function test_custom_state_properties_follow_the_seed_and_clone_contract(): void
    {
        $seed = new \NeuronAI\Tests\Workflow\Stub\OwnedState();
        $workflow = Workflow::make(state: $seed)->addNode(new class () extends Node {
            public function __invoke(StartEvent $event, \NeuronAI\Tests\Workflow\Stub\OwnedState $state): StopEvent
            {
                $state->details->count++;
                return new StopEvent();
            }
        });
        $first = $workflow->run();
        $second = $workflow->run();
        $second->details->count = 9;
        self::assertSame(1, $first->details->count);
        self::assertSame(0, $seed->details->count);
    }

}
