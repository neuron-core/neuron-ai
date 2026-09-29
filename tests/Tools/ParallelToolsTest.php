<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tests\Tools\Stub\AddTool;
use NeuronAI\Tests\Tools\Stub\FailingTool;
use NeuronAI\Tests\Tools\Stub\MultiplyTool;
use NeuronAI\Tests\Tools\Stub\ProcessIdTool;
use NeuronAI\Tests\Tools\Stub\TestToolA;
use NeuronAI\Tests\Tools\Stub\TestToolB;
use NeuronAI\Tests\Tools\Stub\WorkingTool;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function extension_loaded;
use function class_exists;
use function array_values;
use function escapeshellarg;
use function getmypid;
use function iterator_to_array;
use function json_decode;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function shell_exec;

use const PHP_BINARY;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

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

    public function test_parallel_tool_calls_run_each_call_in_its_own_process(): void
    {
        $agent = Agent::make()->setThreadId('thread_1')->parallelToolCalls(true)->addTool(new ProcessIdTool())->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make('process_id', 'call_1'),
                ToolCall::make('process_id', 'call_2'),
            ]),
            new AssistantMessage('Done'),
        ));

        $agent->chat(new UserMessage('Where do tools run?'));

        $processIds = array_values($this->results($agent));
        $this->assertCount(2, $processIds);
        $this->assertNotContains((string) getmypid(), $processIds);
        $this->assertNotSame($processIds[0], $processIds[1]);
    }

    public function test_a_parallel_batch_keeps_the_process_signal_handlers(): void
    {
        $previous = [SIGINT => pcntl_signal_get_handler(SIGINT), SIGQUIT => pcntl_signal_get_handler(SIGQUIT), SIGTERM => pcntl_signal_get_handler(SIGTERM)];
        $asyncSignals = pcntl_async_signals(false);
        // A queue worker installs its graceful stop once, when it starts
        $gracefulStop = static function (): void {
        };
        pcntl_signal(SIGTERM, $gracefulStop);

        try {
            $agent = Agent::make()->setThreadId('thread_1')->parallelToolCalls(true)->addTool(new ProcessIdTool())->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    ToolCall::make('process_id', 'call_1'),
                    ToolCall::make('process_id', 'call_2'),
                ]),
                new AssistantMessage('Done'),
            ));

            $agent->chat(new UserMessage('Where do tools run?'));

            $this->assertNotContains((string) getmypid(), $this->results($agent));
            $this->assertSame($gracefulStop, pcntl_signal_get_handler(SIGTERM));
            $this->assertSame($previous[SIGINT], pcntl_signal_get_handler(SIGINT));
            $this->assertSame($previous[SIGQUIT], pcntl_signal_get_handler(SIGQUIT));
            $this->assertFalse(pcntl_async_signals());
        } finally {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($asyncSignals);
        }
    }

    public function test_tools_run_in_the_calling_process_where_forking_is_disabled(): void
    {
        // PHP-FPM on Debian and Ubuntu keeps pcntl loaded but disables its functions
        $script = <<<'PHP'
            require $argv[1];
            $agent = NeuronAI\Agent\Agent::make()->setThreadId('thread_1')->parallelToolCalls(true)
                ->addTool(new NeuronAI\Tests\Tools\Stub\ProcessIdTool())
                ->setAiProvider(new NeuronAI\Testing\FakeAIProvider(
                    new NeuronAI\Chat\Messages\ToolCallMessage(null, [
                        NeuronAI\Tools\ToolCall::make('process_id', 'call_1'),
                        NeuronAI\Tools\ToolCall::make('process_id', 'call_2'),
                    ]),
                    new NeuronAI\Chat\Messages\AssistantMessage('Done'),
                ));
            $answer = $agent->chat(new NeuronAI\Chat\Messages\UserMessage('Where do tools run?'))->getMessage()?->getContent();
            $results = [];
            foreach ($agent->getChatHistory()->getMessages() as $message) {
                if ($message instanceof NeuronAI\Chat\Messages\ToolResultMessage) {
                    foreach ($message->getToolCalls() as $call) {
                        $results[] = (string) $call->getResult();
                    }
                }
            }
            echo json_encode(['process' => (string) getmypid(), 'answer' => $answer, 'results' => $results]);
            PHP;
        $command = escapeshellarg(PHP_BINARY) . ' -d disable_functions=pcntl_fork -r ' . escapeshellarg($script)
            . ' ' . escapeshellarg(__DIR__ . '/../../vendor/autoload.php') . ' 2>&1';

        $output = json_decode((string) shell_exec($command), true);

        $this->assertIsArray($output);
        $this->assertSame('Done', $output['answer']);
        $this->assertSame([$output['process'], $output['process']], $output['results']);
    }

    public function test_sequential_tool_calls_run_in_the_calling_process(): void
    {
        $agent = Agent::make()->setThreadId('thread_1')->addTool(new ProcessIdTool())->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [
                ToolCall::make('process_id', 'call_1'),
                ToolCall::make('process_id', 'call_2'),
            ]),
            new AssistantMessage('Done'),
        ));

        $agent->chat(new UserMessage('Where do tools run?'));

        $this->assertSame(['call_1' => (string) getmypid(), 'call_2' => (string) getmypid()], $this->results($agent));
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

        $agent = Agent::make()->setThreadId('thread_1');
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $handler = $agent->chat(new UserMessage('Run tools in parallel'));

        $this->assertSame('I have results from both tools.', $handler->getMessage()->getContent());
        $this->assertSame(['call_1' => 'Tool A received: test A', 'call_2' => 'Tool B received: test B'], $this->results($agent));
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
                ToolCall::make($addTool->getName(), 'call_2', ['x' => 5, 'y' => '8']),
            ]),
            new AssistantMessage('Results: multiply=12, add=13')
        );

        $agent = Agent::make()->setThreadId('thread_1');
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($multiplyTool);
        $agent->addTool($addTool);

        $handler = $agent->chat(new UserMessage('Calculate'));

        $this->assertSame('Results: multiply=12, add=13', $handler->getMessage()->getContent());
        $this->assertSame(['call_1' => '12', 'call_2' => '13'], $this->results($agent));
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

        $agent = Agent::make()->setThreadId('thread_1');
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->addTool($failingTool);
        $agent->addTool($workingTool);

        // The error should be propagated
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Tool failing_tool failed with RuntimeException: Tool execution failed');

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

        $agent = Agent::make()->setThreadId('thread_1');
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

        $agent = Agent::make()->setThreadId('thread_1');
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->toolMaxRuns(1);
        $agent->addTool($toolA);
        $agent->addTool($toolB);

        $this->expectException(ToolRunsExceededException::class);
        $this->expectExceptionMessage('Tool tool_a has been executed too many times - 1');

        $agent->chat(new UserMessage('Exceed tool runs'));
    }

    /**
     * @return array<string, string> The settled results of the conversation's tool calls, by call id.
     */
    protected function results(Agent $agent): array
    {
        $results = [];

        foreach ($agent->getChatHistory()->getMessages() as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $call) {
                    $results[(string) $call->getCallId()] = (string) $call->getResult();
                }
            }
        }

        return $results;
    }
}
