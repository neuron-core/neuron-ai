<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Tools\Stub\FailingTool;
use NeuronAI\Tests\Tools\Stub\TestToolA;
use NeuronAI\Tests\Tools\Stub\TestToolB;
use NeuronAI\Tests\Tools\Stub\WorkingTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Fork\Fork;

use function class_exists;
use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const FILE_APPEND;
use const LOCK_EX;

class ParallelToolHooksTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Parallel tool hooks require pcntl and spatie/fork.');
        }
    }

    public function test_before_child_callback_runs_for_each_parallel_tool(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'neuron-parallel-child-');
        $this->assertNotFalse($marker);

        $toolA = (new TestToolA())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));
        $toolB = (new TestToolB())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_1', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_2', ['input' => 'test B']),
            ]),
            new AssistantMessage('Done'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(
            true,
            beforeChild: static fn (): int|false => file_put_contents($marker, '1', FILE_APPEND | LOCK_EX),
        );
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        try {
            $agent->chat(new UserMessage('Run tools in parallel'));

            $this->assertSame('11', file_get_contents($marker));
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function test_before_child_callback_exception_is_propagated(): void
    {
        $toolA = (new TestToolA())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));
        $toolB = (new TestToolB())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_1', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_2', ['input' => 'test B']),
            ]),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(
            true,
            beforeChild: static function (): void {
                throw new RuntimeException('Parallel child initialization failed');
            },
        );
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel child initialization failed');

        $agent->chat(new UserMessage('Run tools in parallel'));
    }

    public function test_after_child_callback_runs_for_each_parallel_tool(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'neuron-parallel-child-');
        $this->assertNotFalse($marker);

        $toolA = (new TestToolA())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));
        $toolB = (new TestToolB())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_1', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_2', ['input' => 'test B']),
            ]),
            new AssistantMessage('Done'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(
            true,
            afterChild: static fn (): int|false => file_put_contents($marker, '1', FILE_APPEND | LOCK_EX),
        );
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        try {
            $agent->chat(new UserMessage('Run tools in parallel'));

            $this->assertSame('11', file_get_contents($marker));
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function test_after_child_callback_runs_when_tool_execution_fails(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'neuron-parallel-child-');
        $this->assertNotFalse($marker);

        $failingTool = (new FailingTool())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));
        $workingTool = (new WorkingTool())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($failingTool->getName(), 'call_1', ['input' => 'test']),
                ToolCall::make($workingTool->getName(), 'call_2', ['input' => 'test']),
            ]),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(
            true,
            afterChild: static fn (): int|false => file_put_contents($marker, '1', FILE_APPEND | LOCK_EX),
        );
        $agent->addTool($failingTool);
        $agent->addTool($workingTool);

        try {
            $agent->chat(new UserMessage('Run tools in parallel'));
            $this->fail('The failing tool did not throw an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Tool execution failed', $exception->getMessage());
            $this->assertSame('11', file_get_contents($marker));
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function test_after_child_callback_exception_is_propagated(): void
    {
        $toolA = (new TestToolA())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool A', true));
        $toolB = (new TestToolB())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input for tool B', true));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($toolA->getName(), 'call_1', ['input' => 'test A']),
                ToolCall::make($toolB->getName(), 'call_2', ['input' => 'test B']),
            ]),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(
            true,
            afterChild: static function (): void {
                throw new RuntimeException('Parallel child cleanup failed');
            },
        );
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel child cleanup failed');

        $agent->chat(new UserMessage('Run tools in parallel'));
    }

    public function test_child_callbacks_are_not_called_for_a_single_tool(): void
    {
        $tool = (new WorkingTool())
            ->addProperty(new ToolProperty('input', PropertyType::STRING, 'Input', true));
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make($tool->getName(), 'call_1', ['input' => 'test']),
            ]),
            new AssistantMessage('Done'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->addTool($tool);
        $agent->parallelToolCalls(
            true,
            beforeChild: static function (): void {
                throw new RuntimeException('A sequential call must not initialize a child.');
            },
            afterChild: static function (): void {
                throw new RuntimeException('A sequential call must not clean up a child.');
            },
        );

        $this->assertSame('Done', $agent->chat(new UserMessage('Run one tool'))->getMessage()->getContent());
    }
}
