<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Agent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolMaxTriesException;
use NeuronAI\Tools\ConcurrentToolCalls;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class ConcurrentToolCallsTest extends TestCase
{
    public function test_concurrent_tool_execution_with_multiple_tools(): void
    {
        // Skip test if the pcntl extension is not available (e.g., on Windows)
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrent tool calls');
        }

        $agent = new class () extends Agent {
            use ConcurrentToolCalls;

            // Make executeTools public for testing
            public function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
            {
                return parent::executeTools($toolCallMessage);
            }
        };

        // Create multiple tools that will be executed concurrently
        $tool1 = Tool::make('tool_one', 'First tool')
            ->setCallable(fn(): string => 'result_one')
            ->setCallId('call_1')
            ->setInputs([]);

        $tool2 = Tool::make('tool_two', 'Second tool')
            ->setCallable(fn(): string => 'result_two')
            ->setCallId('call_2')
            ->setInputs([]);

        $tool3 = Tool::make('tool_three', 'Third tool')
            ->setCallable(fn(): string => 'result_three')
            ->setCallId('call_3')
            ->setInputs([]);

        $toolCallMessage = new ToolCallMessage('Execute tools', [$tool1, $tool2, $tool3]);

        $result = $agent->executeTools($toolCallMessage);

        // Verify that all tools were executed and returned results
        $this->assertInstanceOf(ToolCallResultMessage::class, $result);
        $this->assertCount(3, $result->getTools());

        $tools = $result->getTools();
        $this->assertEquals('result_one', $tools[0]->getResult());
        $this->assertEquals('result_two', $tools[1]->getResult());
        $this->assertEquals('result_three', $tools[2]->getResult());
    }

    public function test_concurrent_tool_execution_with_single_tool(): void
    {
        // Skip test if the pcntl extension is not available
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrent tool calls');
        }

        $agent = new class () extends Agent {
            use ConcurrentToolCalls;

            public function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
            {
                return parent::executeTools($toolCallMessage);
            }
        };

        // Create a single tool - should not use concurrency optimization
        $tool = Tool::make('single_tool', 'Single tool')
            ->setCallable(fn(): string => 'single_result')
            ->setCallId('call_single')
            ->setInputs([]);

        $toolCallMessage = new ToolCallMessage('Execute single tool', [$tool]);

        $result = $agent->executeTools($toolCallMessage);

        // Verify that the tool was executed
        $this->assertInstanceOf(ToolCallResultMessage::class, $result);
        $this->assertCount(1, $result->getTools());
        $this->assertEquals('single_result', $result->getTools()[0]->getResult());
    }

    public function test_concurrent_tool_execution_handles_errors(): void
    {
        // Skip test if the pcntl extension is not available
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrent tool calls');
        }

        $agent = new class () extends Agent {
            use ConcurrentToolCalls;

            public function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
            {
                return parent::executeTools($toolCallMessage);
            }
        };

        $tool1 = Tool::make('success_tool', 'Tool that succeeds')
            ->setCallable(fn(): string => 'success')
            ->setCallId('call_success')
            ->setInputs([]);

        $tool2 = Tool::make('error_tool', 'Tool that throws exception')
            ->setCallable(function (): string {
                throw new ToolException('Tool execution failed');
            })
            ->setCallId('call_error')
            ->setInputs([]);

        $toolCallMessage = new ToolCallMessage('Execute tools with error', [$tool1, $tool2]);

        // Expect the exception to be thrown during concurrent execution
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Tool execution failed');

        $agent->executeTools($toolCallMessage);
    }

    public function test_concurrent_tool_execution_respects_max_tries(): void
    {
        // Skip test if the pcntl extension is not available
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrent tool calls');
        }

        $agent = new class () extends Agent {
            use ConcurrentToolCalls;

            public function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
            {
                return parent::executeTools($toolCallMessage);
            }
        };

        // Set global max tries to 1
        $agent->toolMaxTries(1);

        $tool = Tool::make('test_tool', 'Test tool')
            ->setCallable(fn(): string => 'result')
            ->setCallId('call_1')
            ->setInputs([]);

        $toolCallMessage1 = new ToolCallMessage('First execution', [$tool]);
        $agent->executeTools($toolCallMessage1);

        // The second execution should throw ToolMaxTriesException
        $tool2 = Tool::make('test_tool', 'Test tool')
            ->setCallable(fn(): string => 'result')
            ->setCallId('call_2')
            ->setInputs([]);

        $toolCallMessage2 = new ToolCallMessage('Second execution', [$tool2]);

        $this->expectException(ToolMaxTriesException::class);
        $this->expectExceptionMessage('Tool test_tool has been attempted too many times: 1 attempts.');

        $agent->executeTools($toolCallMessage2);
    }

    public function test_concurrent_tool_execution_with_different_result_types(): void
    {
        // Skip test if the pcntl extension is not available
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrent tool calls');
        }

        $agent = new class () extends Agent {
            use ConcurrentToolCalls;

            public function executeTools(ToolCallMessage $toolCallMessage): ToolCallResultMessage
            {
                return parent::executeTools($toolCallMessage);
            }
        };

        // Test with a string result
        $stringTool = Tool::make('string_tool', 'Returns string')
            ->setCallable(fn(): string => 'string result')
            ->setCallId('call_string')
            ->setInputs([]);

        // Test with array result
        $arrayTool = Tool::make('array_tool', 'Returns array')
            ->setCallable(fn(): array => ['key' => 'value', 'number' => 42])
            ->setCallId('call_array')
            ->setInputs([]);

        // Test with a numeric result
        $numericTool = Tool::make('numeric_tool', 'Returns number')
            ->setCallable(fn(): int => 123)
            ->setCallId('call_numeric')
            ->setInputs([]);

        $toolCallMessage = new ToolCallMessage('Execute tools with different types', [
            $stringTool,
            $arrayTool,
            $numericTool,
        ]);

        $result = $agent->executeTools($toolCallMessage);

        $tools = $result->getTools();
        $this->assertEquals('string result', $tools[0]->getResult());
        $this->assertEquals('{"key":"value","number":42}', $tools[1]->getResult());
        $this->assertEquals('123', $tools[2]->getResult());
    }
}
