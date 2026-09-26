<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;

use function array_map;

class ToolkitHiddenToolTest extends TestCase
{
    protected function toolkitHidingSearch(): ToolkitInterface
    {
        $toolkit = new class () extends AbstractToolkit {
            public function provide(): array
            {
                return [new GetWeatherTool(), new SearchTool()];
            }
        };

        return $toolkit->with(SearchTool::class, fn (ToolInterface $tool): ToolInterface => $tool->visible(false));
    }

    public function test_a_hidden_toolkit_tool_is_not_offered_like_a_hidden_standalone_tool(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done'));
        $agent = Agent::make()->setAiProvider($provider)->addTool($this->toolkitHidingSearch());

        $agent->chat(new UserMessage('Hi'));

        $offered = array_map(
            static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(),
            $provider->getRecorded()[0]->tools
        );
        $this->assertSame(['get_weather'], $offered);
    }

    public function test_a_hidden_toolkit_tool_cannot_be_called_by_the_model(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'x'])]),
            new AssistantMessage('This should not be reached.')
        );
        $agent = Agent::make()->setAiProvider($provider)->addTool($this->toolkitHidingSearch());

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('The tool search is not registered on this agent');

        $agent->chat(new UserMessage('Test'));
    }
}
