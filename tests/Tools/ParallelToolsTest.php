<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolDefinition;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionClass;

use function extension_loaded;
use function usleep;
use function class_exists;

/**
 * Simple test tool that can be serialized for parallel execution.
 */
class TestToolA extends Tool
{
    public function __invoke(string $input): string
    {
        usleep(10000); // 10ms delay
        return "Tool A received: {$input}";
    }
}

/**
 * Simple test tool that can be serialized for parallel execution.
 */
class TestToolB extends Tool
{
    public function __invoke(string $input): string
    {
        usleep(10000); // 10ms delay
        return "Tool B received: {$input}";
    }
}

/**
 * Multiply tool for parallel execution testing.
 */
class MultiplyTool extends Tool
{
    public function __invoke(int $a, int $b): string
    {
        usleep(10000); // 10ms delay
        return (string) ($a * $b);
    }
}

/**
 * Add tool for parallel execution testing.
 */
class AddTool extends Tool
{
    public function __invoke(int $x, int $y): string
    {
        usleep(10000); // 10ms delay
        return (string) ($x + $y);
    }
}

/**
 * Failing tool for error testing.
 */
class FailingTool extends Tool
{
    public function __invoke(string $input): string
    {
        throw new RuntimeException('Tool execution failed');
    }
}

/**
 * Working tool for error testing.
 */
class WorkingTool extends Tool
{
    public function __invoke(string $input): string
    {
        return "Success: {$input}";
    }
}

class ParallelToolsTest extends TestCase
{
    public function setUp(): void
    {
        // Check if pcntl extension is available for parallel execution
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is not available. Skipping parallel tool tests.');
        }

        // Check if spatie/fork package is installed for parallel execution
        if (!class_exists(\Spatie\Fork\Fork::class)) {
            $this->markTestSkipped('spatie/fork package is not installed. Skipping parallel tool tests.');
        }
    }

    public function test_parallel_tool_calls_registers_parallel_tool_node(): void
    {
        $agent = Agent::make();
        $agent->parallelToolCalls(true);

        $tool = ToolDefinition::make('test', 'Test tool');
        $agent->addTool($tool);

        $provider = new FakeAIProvider(
            new AssistantMessage('Hello!')
        );
        $agent->setAiProvider($provider);

        // This should compose the workflow with ParallelToolNode
        $agent->chat(new UserMessage('Hello'));

        // Verify the agent has parallel tool calls enabled
        $reflection = new ReflectionClass($agent);
        $property = $reflection->getProperty('parallelToolCalls');

        $this->assertTrue($property->getValue($agent));
    }

    public function test_two_tools_executed_in_parallel(): void
    {
        $toolA = new TestToolA('tool_a', 'Tool A');
        $toolA->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));

        $toolB = new TestToolB('tool_b', 'Tool B');
        $toolB->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        // First response: model calls both tools
        // Second response: model uses tool results
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $toolA)->setCallId('call_1')->setInputs(['input' => 'test A']),
                (clone $toolB)->setCallId('call_2')->setInputs(['input' => 'test B']),
            ]),
            new AssistantMessage('I have results from both tools.')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $handler = $agent->chat(new UserMessage('Run tools in parallel'));

        $message = $handler->getMessage();

        $this->assertSame('I have results from both tools.', $message->getContent());
        $provider->assertCallCount(2);
    }

    public function test_parallel_execution_returns_correct_results(): void
    {
        $multiplyTool = new MultiplyTool('multiply', 'Multiply two numbers');
        $multiplyTool->addProperty(new ToolProperty('a', PropertyType::INTEGER, 'First number', true));
        $multiplyTool->addProperty(new ToolProperty('b', PropertyType::INTEGER, 'Second number', true));

        $addTool = new AddTool('add', 'Add two numbers');
        $addTool->addProperty(new ToolProperty('x', PropertyType::INTEGER, 'First number', true));
        $addTool->addProperty(new ToolProperty('y', PropertyType::INTEGER, 'Second number', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $multiplyTool)->setCallId('call_1')->setInputs(['a' => 3, 'b' => 4]),
                (clone $addTool)->setCallId('call_2')->setInputs(['x' => 5, 'y' => 7]),
            ]),
            new AssistantMessage('Results: multiply=12, add=12')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($multiplyTool);
        $agent->addTool($addTool);

        $handler = $agent->chat(new UserMessage('Calculate'));

        $this->assertSame('Results: multiply=12, add=12', $handler->getMessage()->getContent());
    }

    public function test_parallel_tool_node_handles_tool_execution_errors(): void
    {
        $failingTool = new FailingTool('failing_tool', 'This tool will fail');
        $failingTool->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $workingTool = new WorkingTool('working_tool', 'This tool works');
        $workingTool->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $failingTool)->setCallId('call_1')->setInputs(['input' => 'test']),
                (clone $workingTool)->setCallId('call_2')->setInputs(['input' => 'test']),
            ]),
            new AssistantMessage('Response')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($failingTool);
        $agent->addTool($workingTool);

        // The error should be propagated
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tool execution failed');

        $agent->chat(new UserMessage('Run failing tool'))->run();
    }

    public function test_parallel_tools_work_in_streaming_mode(): void
    {
        $toolA = new TestToolA('tool_a', 'Tool A');
        $toolA->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));

        $toolB = new TestToolB('tool_b', 'Tool B');
        $toolB->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $toolA)->setCallId('call_1')->setInputs(['input' => 'test A']),
                (clone $toolB)->setCallId('call_2')->setInputs(['input' => 'test B']),
            ]),
            new AssistantMessage('I have results from both tools.')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $handler = $agent->stream(new UserMessage('Run tools in parallel'));

        $state = $handler->run();
        $this->assertSame('I have results from both tools.', $state->getMessage()->getContent());
        $provider->assertCallCount(2);
        $provider->assertMethodCallCount('stream', 2);
    }

    public function test_parallel_tool_node_throws_tool_runs_exceeded_exception(): void
    {
        $toolA = new TestToolA('tool_a', 'Tool A');
        $toolA->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $toolB = new TestToolB('tool_b', 'Tool B');
        $toolB->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $toolA)->setCallId('call_1')->setInputs(['input' => 'test A']),
                (clone $toolB)->setCallId('call_2')->setInputs(['input' => 'test B']),
            ]),
            new ToolCallMessage(null, [
                (clone $toolA)->setCallId('call_3')->setInputs(['input' => 'test A']),
                (clone $toolB)->setCallId('call_4')->setInputs(['input' => 'test B']),
            ]),
            new AssistantMessage('Done')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->toolMaxRuns(1);
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $this->expectException(ToolRunsExceededException::class);

        $agent->chat(new UserMessage('Exceed tool runs'))->run();
    }
}
