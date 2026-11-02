<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\ClosureDependencyTool;
use NeuronAI\Tests\Workflow\Persistence\Stub\KilledProcessPersistence;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\RefusalReason;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Fork\Fork;
use Throwable;

use function array_key_last;
use function class_exists;
use function count;
use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function str_ends_with;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function unlink;

use const FILE_APPEND;

/**
 * A worker killed while a tool runs leaves no result, and a tool that throws
 * proves nothing either: in both cases the effect may have landed. A run
 * recovered after that must not run the call a second time, and must tell the
 * model it does not know what happened.
 */
class ToolAtMostOnceTest extends TestCase
{
    protected const INTERRUPTED = 'The call was interrupted before its result was recorded: it may or may not have taken effect.';

    protected KilledProcessPersistence $persistence;

    protected InMemoryMessageStore $messages;

    protected int $executions = 0;

    protected function setUp(): void
    {
        $this->persistence = new KilledProcessPersistence();
        $this->messages = new InMemoryMessageStore();
    }

    public function test_an_approved_call_killed_before_its_result_was_saved_does_not_run_again(): void
    {
        $tool = $this->debitTool()->requireApproval();
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('I cannot tell whether it went through.'));
        $agent = $this->agent($provider, $tool);

        $this->assertTrue($agent->chat(new UserMessage('Take 10 dollars out'))->isInterrupted());

        $this->persistence->killAt('::tool.call_1.0');
        $this->killed(fn (): mixed => $agent->submitApprovalDecisions(['call_1' => 'approve'])->run());
        $this->assertSame(1, $this->executions);

        $message = $this->recover($provider, $tool)->getMessage();

        $this->assertSame(1, $this->executions, 'A recovered run must not repeat a call that may have taken effect');
        $this->assertSame('I cannot tell whether it went through.', $message->getContent());
        $this->assertSame([self::INTERRUPTED], $this->resultsTheModelRead($provider));
    }

    public function test_a_call_without_approval_killed_before_its_result_was_saved_does_not_run_again(): void
    {
        $tool = $this->debitTool();
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('Done'));

        $this->persistence->killAt('::tool.call_1.0');
        $this->killed(fn (): mixed => $this->agent($provider, $tool)->chat(new UserMessage('Take 10 dollars out')));

        $this->recover($provider, $tool);

        $this->assertSame(1, $this->executions);
        $this->assertSame([self::INTERRUPTED], $this->resultsTheModelRead($provider));
    }

    public function test_a_call_killed_before_it_started_runs_on_recovery(): void
    {
        $tool = $this->debitTool();
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('Done'));

        $this->persistence->killAt('::tool.call_1.0.started');
        $this->killed(fn (): mixed => $this->agent($provider, $tool)->chat(new UserMessage('Take 10 dollars out')));
        $this->assertSame(0, $this->executions);

        $this->recover($provider, $tool);

        $this->assertSame(1, $this->executions);
        $this->assertSame(['debited'], $this->resultsTheModelRead($provider));
    }

    public function test_a_tool_that_threw_after_its_effect_landed_does_not_run_again(): void
    {
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('Please check the account.'));
        $tool = $this->timingOutDebitTool()->requireApproval();

        $this->agent($provider, $tool)->chat(new UserMessage('Take 10 dollars out'));
        $this->timedOut(fn (): mixed => $this->agent($provider, $tool)->submitApprovalDecisions(['call_1' => 'approve'])->run());
        $this->assertSame(1, $this->executions);

        $state = $this->recover($provider, $tool);

        $this->assertFalse($state->isInterrupted(), 'The call settles, it does not wait for a new approval');
        $this->assertSame(1, $this->executions);
        $this->assertSame('Please check the account.', $state->getMessage()->getContent());
        $this->assertSame([self::INTERRUPTED], $this->resultsTheModelRead($provider));
    }

    public function test_an_approval_retried_after_the_tool_threw_does_not_run_it_again(): void
    {
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('Done'));
        $tool = $this->timingOutDebitTool()->requireApproval();

        $this->agent($provider, $tool)->chat(new UserMessage('Take 10 dollars out'));
        $this->timedOut(fn (): mixed => $this->agent($provider, $tool)->submitApprovalDecisions(['call_1' => 'approve'])->run());

        $this->agent($provider, $tool)->submitApprovalDecisions(['call_1' => 'approve'])->run();

        $this->assertSame(1, $this->executions);
        $this->assertSame([self::INTERRUPTED], $this->resultsTheModelRead($provider));
    }

    public function test_a_failure_the_error_handler_settled_keeps_its_result_on_recovery(): void
    {
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('Done'));
        $tool = $this->timingOutDebitTool();
        $handled = fn (Agent $agent): Agent => $agent->toolErrorHandler(
            static fn (Throwable $e): string => "Failed: {$e->getMessage()}"
        );

        $this->persistence->killAt('::tool.result');
        $this->killed(fn (): mixed => $handled($this->agent($provider, $tool))->chat(new UserMessage('Take 10 dollars out')));
        $this->persistence->restart();

        $handled($this->agent($provider, $tool))->run(ExecutionRequest::resume());

        $this->assertSame(1, $this->executions);
        $this->assertSame(['Failed: Connection timed out'], $this->resultsTheModelRead($provider));
    }

    public function test_a_second_kill_keeps_the_outcome_of_the_first(): void
    {
        $tool = $this->debitTool();
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('Done'));

        $this->persistence->killAt('::tool.call_1.0');
        $this->killed(fn (): mixed => $this->agent($provider, $tool)->chat(new UserMessage('Take 10 dollars out')));
        $this->persistence->restart();

        $this->persistence->killAt('::tool.result');
        $this->killed(fn (): mixed => $this->agent($provider, $tool)->run(ExecutionRequest::resume()));

        $this->recover($provider, $tool);

        $this->assertSame(1, $this->executions);
        $this->assertSame([self::INTERRUPTED], $this->resultsTheModelRead($provider));
    }

    public function test_only_the_killed_call_of_a_batch_is_reported_interrupted(): void
    {
        $tool = $this->debitTool();
        $provider = new FakeAIProvider($this->toolCalls('call_1', 'call_2', 'call_3'), new AssistantMessage('Done'));

        $this->persistence->killAt('::tool.call_2.1');
        $this->killed(fn (): mixed => $this->agent($provider, $tool)->chat(new UserMessage('Take 10 dollars out')));
        $this->assertSame(2, $this->executions);

        $this->recover($provider, $tool);

        $this->assertSame(3, $this->executions, 'The call the kill never reached still runs');
        $this->assertSame(['debited', self::INTERRUPTED, 'debited'], $this->resultsTheModelRead($provider));
    }

    public function test_a_call_killed_mid_run_by_a_redelivered_start_does_not_run_again(): void
    {
        $tool = $this->debitTool();
        $provider = new FakeAIProvider($this->toolCalls('call_1'), new AssistantMessage('Done'));
        $delivery = fn (): ExecutionRequest => ExecutionRequest::start(
            new AgentStartEvent([new UserMessage('Take 10 dollars out')]),
            runId: 'run_delivery',
            recoverFailed: true,
        );

        $this->persistence->killAt('::tool.call_1.0');
        $this->killed(fn (): mixed => $this->agent($provider, $tool)->run($delivery()));
        $this->persistence->restart();
        $this->expireLease();

        $this->agent($provider, $tool)->run($delivery());

        $this->assertSame(1, $this->executions);
        $this->assertSame([self::INTERRUPTED], $this->resultsTheModelRead($provider));
    }

    public function test_a_parallel_batch_killed_before_its_results_were_saved_does_not_run_again(): void
    {
        $this->requireForking();

        $runs = tempnam(sys_get_temp_dir(), 'neuron_runs');
        $tool = $this->tool(static function () use ($runs): string {
            file_put_contents($runs, '.', FILE_APPEND);

            return 'debited';
        });
        $provider = new FakeAIProvider($this->toolCalls('call_1', 'call_2'), new AssistantMessage('Done'));

        try {
            $this->persistence->killAt('::parallel.tools');
            $this->killed(fn (): mixed => $this->agent($provider, $tool)->parallelToolCalls(true)->chat(new UserMessage('Take it twice')));
            $this->assertSame(2, strlen((string) file_get_contents($runs)));

            $this->persistence->restart();
            $this->agent($provider, $tool)->parallelToolCalls(true)->run(ExecutionRequest::resume());

            $this->assertSame(2, strlen((string) file_get_contents($runs)));
            $this->assertSame([self::INTERRUPTED, self::INTERRUPTED], $this->resultsTheModelRead($provider));
        } finally {
            unlink($runs);
        }
    }

    public function test_a_tool_that_threw_in_a_parallel_batch_does_not_fail_every_recovery(): void
    {
        $this->requireForking();

        $runs = tempnam(sys_get_temp_dir(), 'neuron_runs');
        $debit = $this->tool(static function () use ($runs): string {
            file_put_contents($runs, '.', FILE_APPEND);

            throw new RuntimeException('Connection timed out');
        })->setName('debit');
        $lookup = $this->tool(static fn (): string => 'found');
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('debit', 'call_1'), ToolCall::make('count_users', 'call_2')]),
            new AssistantMessage('Done'),
        );
        $agent = fn (): Agent => $this->agent($provider, $debit)->addTool($lookup)->parallelToolCalls(true);

        try {
            try {
                $agent()->chat(new UserMessage('Take it out and look it up'));
                $this->fail('The tool should have failed the run');
            } catch (ToolException $e) {
                $this->assertStringContainsString('Connection timed out', $e->getMessage());
            }

            $agent()->run(ExecutionRequest::resume());

            $this->assertSame(1, strlen((string) file_get_contents($runs)));
            $this->assertSame([self::INTERRUPTED, 'found'], $this->resultsTheModelRead($provider));
        } finally {
            unlink($runs);
        }
    }

    public function test_a_process_that_lost_its_run_cannot_start_a_tool(): void
    {
        $persistence = new class () extends InMemoryPersistence {
            public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
            {
                // Another worker claimed the run just before this one reached the tool.
                return !str_ends_with((string) array_key_last($records), '.started')
                    && parent::writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
            }
        };
        $agent = Agent::make(workflowId: 'thread')
            ->setAiProvider(new FakeAIProvider($this->toolCalls('call_1')))
            ->setMessageStore($this->messages)
            ->setPersistence($persistence)
            ->addTool($this->debitTool());

        try {
            $agent->chat(new UserMessage('Take 10 dollars out'));
            $this->fail('A process that lost its run should be refused');
        } catch (WorkflowRefusedException $e) {
            $this->assertSame(RefusalReason::StaleAttempt, $e->reason);
        }

        $this->assertSame(0, $this->executions);
    }

    protected function agent(FakeAIProvider $provider, ToolInterface $tool): Agent
    {
        return Agent::make(workflowId: 'thread')
            ->setLeaseTimeout(null)
            ->setAiProvider($provider)
            ->setMessageStore($this->messages)
            ->setPersistence($this->persistence)
            ->addTool($tool);
    }

    protected function recover(FakeAIProvider $provider, ToolInterface $tool): AgentState
    {
        $this->persistence->restart();

        return $this->agent($provider, $tool)->run(ExecutionRequest::resume());
    }

    protected function expireLease(): void
    {
        $serializer = new PhpSerializer();
        $control = (string) $this->persistence->get('thread', '__control');

        $this->persistence->writeIfUnchanged('thread', '__control', $control, [
            '__control' => $serializer->serialize($serializer->unserialize($control)->heartbeat(time() - 1)),
        ]);
    }

    protected function killed(Closure $run): void
    {
        try {
            $run();
            $this->fail('The process should have been killed');
        } catch (RuntimeException $e) {
            $this->assertSame('The process was killed.', $e->getMessage());
        }
    }

    /** The accounts system finishes the debit, and the client gives up waiting for its answer. */
    protected function timingOutDebitTool(): ClosureDependencyTool
    {
        return $this->tool(function (): string {
            $this->executions++;

            throw new RuntimeException('Connection timed out');
        });
    }

    protected function timedOut(Closure $run): void
    {
        try {
            $run();
            $this->fail('The tool should have failed the run');
        } catch (RuntimeException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
        }
    }

    protected function requireForking(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Parallel tool calls need pcntl, posix and spatie/fork.');
        }
    }

    protected function debitTool(): ClosureDependencyTool
    {
        return $this->tool(function (): string {
            $this->executions++;

            return 'debited';
        });
    }

    protected function tool(Closure $execute): ClosureDependencyTool
    {
        return new ClosureDependencyTool($execute);
    }

    protected function toolCalls(string ...$ids): ToolCallMessage
    {
        $calls = [];
        foreach ($ids as $id) {
            $calls[] = ToolCall::make('count_users', $id);
        }

        return new ToolCallMessage(null, $calls);
    }

    /**
     * @return list<string> The results of the last tool round, as the last request carried them.
     */
    protected function resultsTheModelRead(FakeAIProvider $provider): array
    {
        $requests = $provider->getRecorded();
        $messages = $requests[count($requests) - 1]->messages;
        $last = $messages[count($messages) - 1];
        $this->assertInstanceOf(ToolResultMessage::class, $last);

        $results = [];
        foreach ($last->getToolCalls() as $call) {
            $result = $call->getResult();
            $results[] = $result instanceof ToolOutput ? $result->getText() : $result;
        }

        return $results;
    }
}
