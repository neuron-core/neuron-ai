<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tests\Tools\Stub\AddTool;
use NeuronAI\Tests\Tools\Stub\FailingTool;
use NeuronAI\Tests\Tools\Stub\MultiplyTool;
use NeuronAI\Tests\Tools\Stub\TestToolA;
use NeuronAI\Tests\Tools\Stub\TestToolB;
use NeuronAI\Tests\Tools\Stub\WorkingTool;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ReflectionClass;

use function extension_loaded;
use function class_exists;
use function iterator_to_array;

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

        $tool = new WorkingTool();
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
        $toolA = new TestToolA();
        $toolA->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));

        $toolB = new TestToolB();
        $toolB->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        // First response: model calls both tools
        // Second response: model uses tool results
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_1', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_2', ['input' => 'test B']),
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
        $multiplyTool = new MultiplyTool();
        $multiplyTool->addProperty(new ToolProperty('a', PropertyType::INTEGER, 'First number', true));
        $multiplyTool->addProperty(new ToolProperty('b', PropertyType::INTEGER, 'Second number', true));

        $addTool = new AddTool();
        $addTool->addProperty(new ToolProperty('x', PropertyType::INTEGER, 'First number', true));
        $addTool->addProperty(new ToolProperty('y', PropertyType::INTEGER, 'Second number', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($multiplyTool->getName(), 'call_1', ['a' => 3, 'b' => 4]),
                ToolCall::make($addTool->getName(), 'call_2', ['x' => 5, 'y' => 7]),
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
        $failingTool = new FailingTool();
        $failingTool->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $workingTool = new WorkingTool();
        $workingTool->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($failingTool->getName(), 'call_1', ['input' => 'test']),
                ToolCall::make($workingTool->getName(), 'call_2', ['input' => 'test']),
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

        $agent->chat(new UserMessage('Run failing tool'));
    }

    public function test_parallel_tools_work_in_streaming_mode(): void
    {
        $toolA = new TestToolA();
        $toolA->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));

        $toolB = new TestToolB();
        $toolB->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_1', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_2', ['input' => 'test B']),
            ]),
            new AssistantMessage('I have results from both tools.')
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $generator = $agent->stream(new UserMessage('Run tools in parallel'));

        iterator_to_array($generator);
        $this->assertSame('I have results from both tools.', $generator->getReturn()->getMessage()->getContent());
        $provider->assertCallCount(2);
        $provider->assertMethodCallCount('stream', 2);
    }

    public function test_parallel_tool_node_throws_tool_runs_exceeded_exception(): void
    {
        $toolA = new TestToolA();
        $toolA->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $toolB = new TestToolB();
        $toolB->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_1', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_2', ['input' => 'test B']),
            ]),
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_3', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_4', ['input' => 'test B']),
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

        $agent->chat(new UserMessage('Exceed tool runs'));
    }
}
