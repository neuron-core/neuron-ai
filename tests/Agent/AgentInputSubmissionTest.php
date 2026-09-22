<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\PendingExecution;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;
use function serialize;

class AgentInputSubmissionTest extends TestCase
{
    protected InMemoryPersistence $persistence;
    protected InMemoryChatHistory $history;
    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->history = new InMemoryChatHistory('submission');
        $this->provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true), new ToolCall('browser', 'b', deferred: true)]),
            new AssistantMessage('Finished'),
        );
    }

    protected function agent(bool $approval = false): Agent
    {
        $agent = Agent::make();
        $agent->setChatHistory($this->history)->setPersistence($this->persistence)->setAiProvider($this->provider);
        $tool = new FrontendTool('browser');
        if ($approval) {
            $tool->requireApproval();
        }
        $agent->addTool($tool);
        return $agent;
    }

    public function test_submission_is_read_only_and_partial_approvals_are_accumulated(): void
    {
        $this->agent(true)->chat(new UserMessage('Read the page'));
        $agent = $this->agent(true);
        $before = serialize($this->persistence);
        $request = $agent->submitInputs(['a' => 'approve'], new ApprovalTranslator());
        $this->assertSame($before, serialize($this->persistence));
        $this->assertSame(1, $this->provider->getCallCount());
        $state = $request->run();
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $this->assertTrue($state->getInterruptRequest()->getActions()[0]->isApproved());

        $invocationAgent = $this->agent(true);
        $invocationAgent->submitInputs(['b' => ['reject', 'Cancelled']], new ApprovalTranslator())->run();
        $invocationAgent = $this->agent();
        $state = $invocationAgent->submitInputs(['a' => ['result' => 'Title']], new ToolResultsTranslator())->run();
        $this->assertSame('Finished', $state->getMessage()->getContent());
        $this->assertSame(2, $this->provider->getCallCount());
    }

    public function test_a_reconstructed_agent_inspects_the_interruption_it_is_suspended_on(): void
    {
        $this->assertNull($this->agent(true)->inspect());
        $this->assertSame([], $this->agent(true)->pendingApprovals());

        $state = $this->agent(true)->chat(new UserMessage('Read the page'));

        $run = $this->agent(true)->inspect();
        $this->assertSame(WorkflowStatus::Suspended, $run->status);
        $this->assertEquals($state->getInterruptRequest(), $run->interrupt);
        $this->assertSame(['a', 'b'], $this->pendingApprovalIds());

        $invocationAgent = $this->agent(true);
        $invocationAgent->submitApprovalDecisions(['a' => 'approve'])->run();
        $this->assertSame(['b'], $this->pendingApprovalIds());

        $invocationAgent = $this->agent(true);
        $invocationAgent->submitApprovalDecisions(['b' => ['reject', 'Cancelled']])->run();
        $this->assertSame([], $this->agent(true)->pendingApprovals());
    }

    /** @return string[] */
    protected function pendingApprovalIds(): array
    {
        return array_map(
            static fn (Action $action): string => $action->id,
            $this->agent(true)->pendingApprovals(),
        );
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function approvalDeliveries(): iterable
    {
        foreach (['signal', 'resume', 'translator'] as $first) {
            foreach (['signal', 'resume', 'translator'] as $second) {
                yield $first . '-' . $second => [$first, $second, false];
                yield $first . '-' . $second . '-stream' => [$first, $second, true];
            }
        }
    }

    #[DataProvider('approvalDeliveries')]
    public function test_partial_approvals_survive_reconstruction_and_entry_point_changes(
        string $first,
        string $second,
        bool $streaming,
    ): void {
        $state = $this->agent(true)->chat(new UserMessage('Read the page'));
        foreach ([[$first, ['a' => 'approve']], [$second, ['b' => ['reject', 'Cancelled']]]] as [$source, $decisions]) {
            $agent = $this->agent(true);
            $request = match ($source) {
                'signal' => new PendingExecution($agent, \NeuronAI\Workflow\Executor\ExecutionRequest::signal('approval', $decisions)),
                'resume' => new PendingExecution($agent, \NeuronAI\Workflow\Executor\ExecutionRequest::resume($decisions)),
                'translator' => $agent->submitApprovalDecisions($decisions),
                default => $this->fail('Unknown approval delivery source.'),
            };
            if ($streaming) {
                $events = $request->events();
                iterator_to_array($events);
                $state = $events->getReturn();
            } else {
                $state = $request->run();
            }
        }

        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(\NeuronAI\Agent\Interrupt\ToolResultsRequest::class, $request);
        $this->assertSame(['a'], array_map(fn (ToolCall $call): ?string => $call->getCallId(), $request->getToolCalls()));
        $state = $this->agent()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::signal('tool_results', ['a' => ['result' => 'Title']]));
        $this->assertSame('Finished', $state->getMessage()->getContent());
        $this->assertSame(2, $this->provider->getCallCount());
    }

    public function test_explicit_updates_replace_previous_partial_approval_decisions(): void
    {
        $this->agent(true)->chat(new UserMessage('Read the page'));
        $this->agent(true)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::signal('approval', ['a' => 'approve']));
        $invocationAgent = $this->agent(true);
        $state = $invocationAgent->submitApprovalDecisions(['a' => ['reject', 'Changed my mind']])->run();
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $this->assertTrue($state->getInterruptRequest()->getActions()[0]->isRejected());

        $state = $this->agent(true)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['b' => 'approve']));
        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(\NeuronAI\Agent\Interrupt\ToolResultsRequest::class, $request);
        $this->assertSame(['b'], array_map(fn (ToolCall $call): ?string => $call->getCallId(), $request->getToolCalls()));
        $state = $this->agent()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::signal('tool_results', ['b' => ['result' => 'Title']]));
        $this->assertSame('Finished', $state->getMessage()->getContent());
    }

    public function test_empty_or_unmatched_payload_does_not_stage_an_inputless_continuation(): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $agent = $this->agent();
        $before = serialize($this->persistence);
        foreach ([[], ['unknown' => ['result' => 'bad']]] as $payload) {
            try {
                $agent->submitInputs($payload, new ToolResultsTranslator());
                $this->fail('An empty or unmatched delivery must fail before staging.');
            } catch (InputTranslationException) {
                $this->assertSame($before, serialize($this->persistence));
            }
        }
        $state = $agent->submitInputs(['a' => ['result' => null], 'b' => ['result' => false]], new ToolResultsTranslator())->run();
        $this->assertFalse($state->isInterrupted());
    }

    public function test_submission_without_a_persisted_run_does_not_start_one(): void
    {
        $before = serialize($this->persistence);
        try {
            $this->agent()->submitInputs(['a' => 'approve'], new ApprovalTranslator());
            $this->fail('Submission requires an existing run.');
        } catch (InputTranslationException $exception) {
            $this->assertStringContainsString('no persisted run', $exception->getMessage());
        }
        $this->assertSame($before, serialize($this->persistence));
        $this->assertSame(0, $this->provider->getCallCount());
    }

    public function test_custom_translator_receives_authoritative_requests(): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $translator = $this->createMock(InputTranslatorInterface::class);
        $translator->expects($this->once())->method('translate')->willReturnCallback(
            function (array $payload, ToolResultsRequest $request): array {
                $this->assertSame(['custom' => 'Title'], $payload);
                $this->assertCount(2, $request->getToolCalls());
                return [
                    'a' => ['result' => $payload['custom']], 'b' => ['error' => 'Cancelled'],
                ];
            },
        );
        $invocationAgent = $this->agent();
        $stream = $invocationAgent->submitInputs(['custom' => 'Title'], $translator)->events();
        iterator_to_array($stream);
        $this->assertFalse($stream->getReturn()->isInterrupted());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_staged_inputs_cannot_be_rematched_after_another_request_advances_the_run(bool $streaming): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $agent = $this->agent();
        $request = $agent->submitInputs(['b' => ['result' => 'URL']], new ToolResultsTranslator());
        $invocationAgent = $this->agent();
        $invocationAgent->submitInputs(['a' => ['result' => 'Title']], new ToolResultsTranslator())->run();
        $before = serialize($this->persistence);
        try {
            if ($streaming) {
                iterator_to_array($request->events());
            } else {
                $request->run();
            }
            $this->fail('An intervening continuation must invalidate the inspected snapshot.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('Stale continuation', $exception->getMessage());
        }
        $this->assertSame($before, serialize($this->persistence));
        $this->assertFalse($agent->submitInputs(['b' => ['result' => 'URL']], new ToolResultsTranslator())->run()->isInterrupted());
    }

    /** @return iterable<string, array{string}> */
    public static function conflictingInputs(): iterable
    {
        foreach (['submit', 'signal', 'inputs', 'runId', 'attempt'] as $source) {
            yield $source => [$source];
        }
    }

    #[DataProvider('conflictingInputs')]
    public function test_preparing_another_request_does_not_discard_the_original(string $source): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $agent = $this->agent();
        $original = $agent->submitInputs(['a' => ['result' => 'Title'], 'b' => ['result' => 'URL']], new ToolResultsTranslator());
        $other = match ($source) {
            'submit' => $agent->submitInputs(['a' => ['result' => 'Changed']], new ToolResultsTranslator()),
            'signal' => \NeuronAI\Workflow\Executor\ExecutionRequest::signal('tool_results', []),
            'inputs' => \NeuronAI\Workflow\Executor\ExecutionRequest::resume(),
            'runId' => \NeuronAI\Workflow\Executor\ExecutionRequest::resume(expectedRunId: 'other'),
            'attempt' => \NeuronAI\Workflow\Executor\ExecutionRequest::resume(expectedExecutionAttempt: 9),
            default => $this->fail('Unknown request source.'),
        };
        $this->assertNotSame($original, $other);
        $this->assertFalse($original->run()->isInterrupted());
    }

    public function test_submission_does_not_replace_an_existing_signal_request(): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $agent = $this->agent();
        $signal = \NeuronAI\Workflow\Executor\ExecutionRequest::signal('tool_results', ['a' => ['result' => 'Title'], 'b' => ['result' => 'URL']]);
        $agent->submitInputs(['a' => ['result' => 'Changed']], new ToolResultsTranslator());
        $this->assertFalse($agent->run($signal)->isInterrupted());
    }
}
