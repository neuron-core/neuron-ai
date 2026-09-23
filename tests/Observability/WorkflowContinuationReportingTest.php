<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowInterrupted;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Workflow\Executor\Stub\ConcurrentWaitNode;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Stub\InterruptableNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class WorkflowContinuationReportingTest extends TestCase
{
    public function test_start_reports_the_current_attempt_before_listeners_run(): void
    {
        $starts = [];
        $workflow = Workflow::make('reporting')
            ->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);
        $workflow->subscribe(WorkflowStart::class, function (WorkflowStart $event) use ($workflow, &$starts): void {
            $this->assertSame($workflow, $event->source);
            $context = $event->execution;
            $starts[] = [
                $context->workflowId, $context->runId, $context->executionAttempt,
                WorkflowStatus::Running, null,
            ];
        });

        $first = $workflow->run();
        $runId = $first->getRunId();
        $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));

        $this->assertSame([
            ['reporting', $runId, 1, WorkflowStatus::Running, null],
            ['reporting', $runId, 2, WorkflowStatus::Running, null],
        ], $starts);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_native_agent_submissions_report_approval_then_results_then_completion(bool $streaming): void
    {
        $agent = Agent::make(workflowId: 'reporting-agent');
        $agent
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    new ToolCall('browser', 'a', deferred: true),
                    new ToolCall('browser', 'b', deferred: true),
                ]),
                new AssistantMessage('Finished'),
            ))
            ->addTool((new FrontendTool('browser'))->requireApproval());
        $events = [];
        $agent->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$events): void {
            if ($event instanceof WorkflowInterrupted) {
                $events[] = ['interrupted', $event->state->getInterruptRequest()::class];
            } elseif ($event instanceof WorkflowEnd) {
                $events[] = ['end', $event->state->getStatus()];
            } elseif ($event instanceof WorkflowStart) {
                $events[] = ['start'];
            } elseif ($event instanceof ToolCalling || $event instanceof ToolCalled) {
                $events[] = [$event->name(), $event->tool->getCallId()];
            } elseif ($event instanceof AgentError) {
                $events[] = ['error'];
            }
        });

        $agent->chat(new UserMessage('Read both pages'));
        $this->assertSame([
            ['start'], ['interrupted', ApprovalRequest::class], ['end', WorkflowStatus::Suspended],
        ], $events);

        $submissions = [
            fn (): \NeuronAI\Workflow\PendingExecution => $agent->submitApprovalDecisions(['a' => 'approve']),
            fn (): \NeuronAI\Workflow\PendingExecution => $agent->submitApprovalDecisions(['b' => 'approve']),
            fn (): \NeuronAI\Workflow\PendingExecution => $agent->submitToolResults(['a' => ['result' => 'First page']]),
            fn (): \NeuronAI\Workflow\PendingExecution => $agent->submitToolResults(['b' => ['result' => 'Second page']]),
        ];
        $expectedRequests = [ApprovalRequest::class, ToolResultsRequest::class, ToolResultsRequest::class];
        foreach ($submissions as $index => $submit) {
            $events = [];
            $request = $submit();
            $this->assertSame([], $events, 'Staging input must not report execution.');
            if ($streaming) {
                $stream = $request->events();
                $this->assertSame([], $events, 'Creating the stream must not report execution.');
                iterator_to_array($stream);
            } else {
                $request->run();
            }
            $this->assertSame($index < 3 ? [
                ['start'],
                ...($index === 1 ? [['tool-calling', 'a'], ['tool-calling', 'b']] : []),
                ['interrupted', $expectedRequests[$index]], ['end', WorkflowStatus::Suspended],
            ] : [
                ['start'], ['tool-called', 'a'], ['tool-called', 'b'], ['end', WorkflowStatus::Completed],
            ], $events);
        }
    }

    public function test_parallel_requests_are_reported_only_when_they_become_current(): void
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
            {
                return new DocumentParallelEvent(['b' => new TextProcessEvent(), 'a' => new TextProcessEvent()]);
            }
        };
        $join = new class () extends Node {
            public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        };
        $trace = (object) ['events' => []];
        $events = [];
        $workflow = Workflow::make('test-workflow')->setExecutor(new AsyncExecutor())
            ->addNodes([$fork, new ConcurrentWaitNode($trace), $join]);
        $workflow->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event) use (&$events): void {
            if ($event instanceof WorkflowInterrupted) {
                $request = $event->state->getInterruptRequest();
                $this->assertInstanceOf(WaitForEventRequest::class, $request);
                $events[] = $request->getEventName();
            } elseif ($event instanceof WorkflowEnd) {
                $events[] = $event->state->getStatus()->value;
            } elseif ($event instanceof AgentError) {
                $events[] = 'error';
            }
        });

        $workflow->run();
        $this->assertSame(['b.started', 'a.started', 'a.waiting', 'b.waiting'], $trace->events);
        $this->assertSame(['a', 'suspended'], $events);
        $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        $this->assertSame(['a', 'suspended'], $events, 'Reading an unanswered request does not report another suspension.');
        $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));
        $this->assertSame(['a', 'suspended', 'b', 'suspended'], $events);
        $workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));
        $this->assertSame(['a', 'suspended', 'b', 'suspended', 'completed'], $events);
    }
}
