<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class ReproDetailedException extends RuntimeException
{
    /** @param array<string, int> $details */
    public function __construct(public array $details)
    {
        parent::__construct('Upstream failed with code ' . $details['code']);
    }
}

class ReproDetailedFailureTool extends Tool
{
    protected string $name = 'upstream';

    protected ?string $description = 'Calls an upstream service';

    public function __invoke(): string
    {
        throw new ReproDetailedException(['code' => 503]);
    }
}

class ParallelCustomExceptionTest extends TestCase
{
    public function test_a_child_exception_with_a_custom_constructor_reaches_the_error_handler(): void
    {
        $handled = [];
        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    new ToolCall('upstream', 'a'),
                    new ToolCall('search', 'b', ['query' => 'PHP']),
                ]),
                new AssistantMessage('Done'),
            ))
            ->addTool([new ReproDetailedFailureTool(), new SearchTool()])
            ->parallelToolCalls()
            ->toolErrorHandler(function (Throwable $error) use (&$handled): string {
                $handled[] = [$error::class, $error->getMessage()];
                return 'handled: ' . $error->getMessage();
            });

        $state = $agent->chat(new UserMessage('Call both'));

        $this->assertSame('Done', $state->getMessage()?->getContent());
        $this->assertSame([[ReproDetailedException::class, 'Upstream failed with code 503']], $handled);
    }

    public function test_the_same_tool_in_sequential_mode_reaches_the_error_handler(): void
    {
        $agent = Agent::make()
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    new ToolCall('upstream', 'a'),
                    new ToolCall('search', 'b', ['query' => 'PHP']),
                ]),
                new AssistantMessage('Done'),
            ))
            ->addTool([new ReproDetailedFailureTool(), new SearchTool()])
            ->toolErrorHandler(fn (Throwable $error): string => 'handled: ' . $error->getMessage());

        $state = $agent->chat(new UserMessage('Call both'));

        $this->assertSame('Done', $state->getMessage()?->getContent());
    }
}
