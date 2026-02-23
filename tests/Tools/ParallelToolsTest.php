<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
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

        $tool = Tool::make('test', 'Test tool');
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

        // Track chunks to verify parallel execution
        $toolCallChunks = [];
        $toolResultChunks = [];
        foreach ($handler->events() as $event) {
            if ($event instanceof ToolCallChunk) {
                $toolCallChunks[] = $event->tool->getName();
            }
            if ($event instanceof ToolResultChunk) {
                $toolResultChunks[] = $event->tool->getName();
            }
        }

        $message = $handler->getMessage();

        // Both tools should have been called
        $this->assertContains('tool_a', $toolCallChunks);
        $this->assertContains('tool_b', $toolCallChunks);

        // Both tools should have results
        $this->assertContains('tool_a', $toolResultChunks);
        $this->assertContains('tool_b', $toolResultChunks);

        // Verify final response
        $this->assertSame('I have results from both tools.', $message->getContent());

        // Provider should have been called twice (tool calls + final response)
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

        // Collect tool result chunks to verify results
        $toolResults = [];
        foreach ($handler->events() as $event) {
            if ($event instanceof ToolResultChunk) {
                $toolResults[$event->tool->getName()] = $event->tool->getResult();
            }
        }

        // Verify both tools returned correct results
        $this->assertArrayHasKey('multiply', $toolResults);
        $this->assertArrayHasKey('add', $toolResults);
        $this->assertSame('12', $toolResults['multiply']); // 3 * 4 = 12
        $this->assertSame('12', $toolResults['add']);     // 5 + 7 = 12

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
}
