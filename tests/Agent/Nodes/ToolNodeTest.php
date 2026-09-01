<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Tests\Agent\Nodes\Stub\TestToolWithRequiredInput;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tests\Agent\Stub\TestParametrizedTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;
use Throwable;

class ToolNodeTest extends TestCase
{
    /**
     * @param ToolInterface[] $registry
     * @param ToolCall[] $calls
     */
    private function runNode(array $registry, array $calls, AgentState $state, ?callable $errorHandler = null, int $maxRuns = 10): void
    {
        $toolNode = new ToolNode(
            new InMemoryChatHistory(),
            maxRuns: $maxRuns,
            errorHandler: $errorHandler
        );

        $toolCallMessage = new ToolCallMessage(null, $calls);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test instructions', tools: $registry);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        // Set up the workflow context (required for Node::emit() to work)
        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }
    }

    /**
     * Test that MissingCallbackParameter is caught by error handler
     * and the result is set on the call directly.
     */
    public function test_error_handler_catches_missing_required_parameter(): void
    {
        // A call WITHOUT the required property - this triggers MissingCallbackParameter
        $call = ToolCall::make('test_tool', 'call_1', []);

        $errorHandler = fn (Throwable $e, ToolCall $call): string => "Error handled: {$e->getMessage()}";

        // Should NOT throw because the error handler catches it
        $this->runNode([new TestToolWithRequiredInput()], [$call], new AgentState(), $errorHandler);

        // The call result was set to the error handler's message
        $this->assertSame(
            'Error handled: Missing required parameter: required_input',
            $call->getResult()
        );
    }

    /**
     * A handler returning null declines: the exception propagates instead of
     * leaving the call silently result-less.
     */
    public function test_error_handler_returning_null_declines_and_exception_propagates(): void
    {
        $call = ToolCall::make('test_tool', 'call_1', []);

        $errorHandler = fn (Throwable $e, ToolCall $call): ?string => null;

        $this->expectException(MissingCallbackParameter::class);

        $this->runNode([new TestToolWithRequiredInput()], [$call], new AgentState(), $errorHandler);
    }

    public function test_error_handler_can_return_error_output(): void
    {
        $call = ToolCall::make('test_tool', 'call_1', []);

        $errorHandler = fn (Throwable $e, ToolCall $call): ToolOutput => ToolOutput::error($e->getMessage());

        $this->runNode([new TestToolWithRequiredInput()], [$call], new AgentState(), $errorHandler);

        $result = $call->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Missing required parameter: required_input', $result->getText());
    }

    /**
     * Test that MissingCallbackParameter escapes when no error handler is set.
     */
    public function test_missing_required_parameter_throws_without_error_handler(): void
    {
        $call = ToolCall::make('test_tool', 'call_1', []);

        $this->expectException(MissingCallbackParameter::class);
        $this->expectExceptionMessage('Missing required parameter: required_input');

        $this->runNode([new TestToolWithRequiredInput()], [$call], new AgentState());
    }

    public function test_parameterized_tool_tracked_by_run_key(): void
    {
        $registry = [new TestParametrizedTool('param_tool')];
        $state = new AgentState();

        // Two executions with the same run-key-relevant input
        $this->runNode($registry, [ToolCall::make('param_tool', 'call_1', ['key' => 'offset=0'])], $state, maxRuns: 2);
        $this->runNode($registry, [ToolCall::make('param_tool', 'call_2', ['key' => 'offset=0'])], $state, maxRuns: 2);

        $this->assertSame(2, $state->getToolRuns('param_tool:offset=0'));
        $this->assertSame(0, $state->getToolRuns('param_tool')); // Name tracking unused
    }

    public function test_different_parameters_tracked_separately(): void
    {
        $registry = [new TestParametrizedTool('read_tool')];
        $state = new AgentState();

        // First call with offset=0, second with offset=100 — different run keys,
        // both succeed under maxRuns 1.
        $this->runNode($registry, [ToolCall::make('read_tool', 'call_1', ['key' => 'offset=0'])], $state, maxRuns: 1);
        $this->runNode($registry, [ToolCall::make('read_tool', 'call_2', ['key' => 'offset=100'])], $state, maxRuns: 1);

        $this->assertSame(1, $state->getToolRuns('read_tool:offset=0'));
        $this->assertSame(1, $state->getToolRuns('read_tool:offset=100'));
    }

    public function test_regular_tool_tracked_by_name(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'regular_tool';
            protected ?string $description = 'A regular tool';

            public function __invoke(): string
            {
                return 'ok';
            }
        };

        $state = new AgentState();
        $this->runNode([$tool], [ToolCall::make('regular_tool', 'call_1', [])], $state, maxRuns: 2);

        $this->assertSame(1, $state->getToolRuns('regular_tool'));
        $this->assertSame(0, $state->getToolRuns('regular_tool:any_key'));
    }

    public function test_max_runs_enforced_per_run_key(): void
    {
        $registry = [new TestParametrizedTool('bounded_tool')];
        $state = new AgentState();

        // First call succeeds
        $this->runNode($registry, [ToolCall::make('bounded_tool', 'call_1', ['key' => 'id=123'])], $state, maxRuns: 1);

        // Second call with the same run key should throw
        $this->expectException(ToolRunsExceededException::class);
        $this->expectExceptionMessage('Tool bounded_tool has been executed too many times - 1');

        $this->runNode($registry, [ToolCall::make('bounded_tool', 'call_2', ['key' => 'id=123'])], $state, maxRuns: 1);
    }

    public function test_different_parameters_allow_more_calls_than_max_runs(): void
    {
        $registry = [new TestParametrizedTool('multi_tool')];
        $state = new AgentState();

        // Each unique parameter combination gets its own maxRuns budget.
        foreach (['id=1', 'id=2', 'id=3'] as $index => $key) {
            $this->runNode($registry, [ToolCall::make('multi_tool', 'call_' . $index, ['key' => $key])], $state, maxRuns: 1);
        }

        $this->assertSame(1, $state->getToolRuns('multi_tool:id=1'));
        $this->assertSame(1, $state->getToolRuns('multi_tool:id=2'));
        $this->assertSame(1, $state->getToolRuns('multi_tool:id=3'));
    }
}
