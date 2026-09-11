<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsTranslator;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\DeferredTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        $tool = new DeferredTool('browser');
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
        $this->assertSame($agent, $agent->submitInputs(['a' => 'approve'], new ApprovalTranslator()));
        $this->assertSame($before, serialize($this->persistence));
        $this->assertSame(1, $this->provider->getCallCount());
        $state = $agent->run();
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $this->assertTrue($state->getInterruptRequest()->getActions()[0]->isApproved());

        $this->agent(true)->submitInputs(['b' => ['reject', 'Cancelled']], new ApprovalTranslator())->run();
        $state = $this->agent()->submitInputs(['a' => ['result' => 'Title']], new ToolResultsTranslator())->run();
        $this->assertSame('Finished', $state->getMessage()->getContent());
        $this->assertSame(2, $this->provider->getCallCount());
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
            function (array $payload, array $requests): array {
                $this->assertSame(['custom' => 'Title'], $payload);
                $this->assertCount(1, $requests);
                return [ResumeInput::event(array_values($requests)[0], [
                    'a' => ['result' => $payload['custom']], 'b' => ['error' => 'Cancelled'],
                ])];
            },
        );
        $stream = $this->agent()->submitInputs(['custom' => 'Title'], $translator)->events();
        iterator_to_array($stream);
        $this->assertFalse($stream->getReturn()->isInterrupted());
    }

    public function test_staged_inputs_cannot_be_rematched_after_another_request_advances_the_run(): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $agent = $this->agent()->submitInputs(['b' => ['result' => 'URL']], new ToolResultsTranslator());
        $this->agent()->submitInputs(['a' => ['result' => 'Title']], new ToolResultsTranslator())->run();
        $before = serialize($this->persistence);
        try {
            $agent->run();
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
    public function test_conflicting_input_sources_do_not_discard_the_staged_delivery(string $source): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $agent = $this->agent()->submitInputs(['a' => ['result' => 'Title'], 'b' => ['result' => 'URL']], new ToolResultsTranslator());
        try {
            match ($source) {
                'submit' => $agent->submitInputs(['a' => ['result' => 'Changed']], new ToolResultsTranslator()),
                'signal' => $agent->signal('tool_results', []),
                'inputs' => $agent->resume()->events(),
                'runId' => $agent->resume(expectedRunId: 'other')->events(),
                'attempt' => $agent->resume(expectedExecutionAttempt: 9)->events(),
                default => $this->fail('Unknown conflict source.'),
            };
            $this->fail('Combining continuation sources must fail.');
        } catch (WorkflowException) {
        }
        $this->assertFalse($agent->run()->isInterrupted());
    }

    public function test_submission_does_not_replace_a_staged_signal(): void
    {
        $this->agent()->chat(new UserMessage('Read the page'));
        $agent = $this->agent()->signal('tool_results', ['a' => ['result' => 'Title'], 'b' => ['result' => 'URL']]);
        try {
            $agent->submitInputs(['a' => ['result' => 'Changed']], new ToolResultsTranslator());
            $this->fail('Submitted inputs must not replace a staged signal.');
        } catch (WorkflowException) {
        }
        $this->assertFalse($agent->run()->isInterrupted());
    }
}
