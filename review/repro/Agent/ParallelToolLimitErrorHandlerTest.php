<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_map;

class ParallelToolLimitErrorHandlerTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_the_error_handler_settles_an_exceeded_limit_in_both_modes(bool $parallel): void
    {
        $messages = new InMemoryMessageStore();
        $agent = Agent::make()
            ->setMessageStore($messages)
            ->setThreadId('parallel-limit')
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    new ToolCall('lookup', 'a', ['query' => 'PHP']),
                    new ToolCall('lookup', 'b', ['query' => 'Rust']),
                    new ToolCall('lookup', 'c', ['query' => 'Go']),
                ]),
                new AssistantMessage('Done'),
            ))
            ->addTool(new CountingTool())
            ->toolMaxRuns(2)
            ->parallelToolCalls($parallel)
            ->toolErrorHandler(fn (Throwable $error, ToolCall $call): string => "{$call->getCallId()}: " . $error::class);

        $state = $agent->chat(new UserMessage('Look up all three'));

        $this->assertSame('Done', $state->getMessage()?->getContent());
        $this->assertSame(3, $state->getToolRuns('lookup'));
        $result = $messages->loadActive('parallel-limit')[2];
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertSame(
            ['Results for: PHP', 'Results for: Rust', 'c: ' . ToolRunsExceededException::class],
            array_map(static fn (ToolCall $call): string|ToolOutput => $call->getResult(), $result->getToolCalls())
        );
    }
}
