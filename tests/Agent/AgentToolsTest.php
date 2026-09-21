<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Agent\Stub\WeatherAgent;
use NeuronAI\Tests\Agent\Stub\WeatherToolkit;
use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AgentToolsTest extends TestCase
{
    public function test_added_tools_complement_defaults_until_tools_are_replaced(): void
    {
        $agent = new WeatherAgent();
        $search = new SearchTool();
        $agent->addTool($search);

        $this->assertSame($search, $agent->getTools()[0]);
        $this->assertInstanceOf(WeatherToolkit::class, $agent->getTools()[1]);

        $replacement = (new SearchTool())->setDescription('Search application documents');
        $this->assertSame($agent, $agent->setTools([$replacement]));
        $this->assertSame([$replacement], $agent->getTools());
        $this->assertSame([$replacement], $agent->bootstrapTools());

        $providerTool = new ProviderTool('web_search');
        $agent->addTool($providerTool);
        $this->assertSame([$replacement, $providerTool], $agent->bootstrapTools());

        $agent->setTools([$search]);
        $this->assertSame([$search], $agent->getTools());
    }

    public function test_empty_override_removes_defaults_and_allows_later_additions(): void
    {
        $agent = new WeatherAgent();
        $agent->addTool(new SearchTool());
        $agent->bootstrapTools();

        $agent->setTools([]);

        $this->assertSame([], $agent->getTools());
        $this->assertSame([], $agent->bootstrapTools());

        $search = new SearchTool();
        $agent->addTool($search);
        $this->assertSame([$search], $agent->bootstrapTools());
    }

    public function test_replacing_tools_refreshes_toolkit_guidelines_and_cached_tools(): void
    {
        $agent = new WeatherAgent();
        $agent->setInstructions('Application instructions');
        $agent->bootstrapTools();
        $this->assertStringContainsString('Always report temperatures in Celsius.', $agent->getInstructions()->getContent());

        $agent->setTools([]);
        $this->assertSame([], $agent->bootstrapTools());
        $this->assertSame('Application instructions', $agent->getInstructions()->getContent());

        $agent->setTools([new WeatherToolkit(), new ProviderTool('web_search')]);
        $this->assertCount(2, $agent->bootstrapTools());
        $this->assertStringContainsString('Always report temperatures in Celsius.', $agent->getInstructions()->getContent());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTools(): iterable
    {
        yield 'string' => ['invalid'];
        yield 'null' => [null];
        yield 'array' => [[]];
    }

    #[DataProvider('invalidTools')]
    public function test_invalid_override_preserves_the_existing_configuration(mixed $invalidTool): void
    {
        $agent = new WeatherAgent();
        $search = new SearchTool();
        $agent->addTool($search);
        $tools = $agent->bootstrapTools();

        try {
            $agent->setTools([new ProviderTool('web_search'), $invalidTool]);
            $this->fail('Expected invalid tools to be rejected.');
        } catch (AgentException $exception) {
            $this->assertSame('Tools must be an instance of ToolInterface, ToolkitInterface, or ProviderToolInterface', $exception->getMessage());
        }

        $this->assertSame($search, $agent->getTools()[0]);
        $this->assertInstanceOf(WeatherToolkit::class, $agent->getTools()[1]);
        $this->assertSame($tools, $agent->bootstrapTools());
    }
}
