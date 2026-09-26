<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PHPUnit\Framework\TestCase;

class ToolkitGuidelinesListTest extends TestCase
{
    public function test_every_toolkit_tool_is_listed_as_a_bullet(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done'));
        $agent = Agent::make()->setAiProvider($provider)->setInstructions('Base')->addTool(new class () extends AbstractToolkit {
            public function guidelines(): ?string
            {
                return 'Use wisely.';
            }

            public function provide(): array
            {
                return [new GetWeatherTool(), new SearchTool()];
            }
        });

        $agent->chat(new UserMessage('Hi'));

        $prompt = (string) $provider->getRecorded()[0]->systemPrompt?->getContent();
        $this->assertStringContainsString("Use wisely.\n- get_weather\n- search\n</TOOLS-GUIDELINES>", $prompt);
        $this->assertStringNotContainsString("\0", $prompt);
        $this->assertStringNotContainsString(__FILE__, $prompt);
    }
}
