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
use NeuronAI\Tests\Support\ExecutionTestFactory;

class AgentToolsTest extends TestCase
{
    public function test_added_tools_complement_defaults_until_tools_are_replaced(): void
    {
        $agent = (new WeatherAgent())->setAiProvider(new \NeuronAI\Testing\FakeAIProvider());
        $search = new SearchTool();
        $agent->addTool($search);

        $this->assertSame($search, $agent->getTools()[0]);
        $this->assertInstanceOf(WeatherToolkit::class, $agent->getTools()[1]);

        $replacement = (new SearchTool())->setDescription('Search application documents');
        $this->assertSame($agent, $agent->setTools([$replacement]));
        $this->assertSame([$replacement], $agent->getTools());
        $this->assertSame([$replacement], ExecutionTestFactory::agentResources($agent)->tools->all());

        $providerTool = new ProviderTool('web_search');
        $agent->addTool($providerTool);
        $this->assertSame([$replacement, $providerTool], ExecutionTestFactory::agentResources($agent)->tools->all());

        $agent->setTools([$search]);
        $this->assertSame([$search], $agent->getTools());
    }

    public function test_empty_override_removes_defaults_and_allows_later_additions(): void
    {
        $agent = (new WeatherAgent())->setAiProvider(new \NeuronAI\Testing\FakeAIProvider());
        $agent->addTool(new SearchTool());
        ExecutionTestFactory::agentResources($agent)->tools->all();

        $agent->setTools([]);

        $this->assertSame([], $agent->getTools());
        $this->assertSame([], ExecutionTestFactory::agentResources($agent)->tools->all());

        $search = new SearchTool();
        $agent->addTool($search);
        $this->assertSame([$search], ExecutionTestFactory::agentResources($agent)->tools->all());
    }

    public function test_replacing_tools_refreshes_toolkit_guidelines_and_cached_tools(): void
    {
        $agent = (new WeatherAgent())->setAiProvider(new \NeuronAI\Testing\FakeAIProvider());
        $agent->setInstructions('Application instructions');
        ExecutionTestFactory::agentResources($agent)->tools->all();
        $this->assertStringContainsString('Always report temperatures in Celsius.', ExecutionTestFactory::agentResources($agent)->instructions->getContent());

        $agent->setTools([]);
        $this->assertSame([], ExecutionTestFactory::agentResources($agent)->tools->all());
        $this->assertSame('Application instructions', ExecutionTestFactory::agentResources($agent)->instructions->getContent());

        $agent->setTools([new WeatherToolkit(), new ProviderTool('web_search')]);
        $this->assertCount(2, ExecutionTestFactory::agentResources($agent)->tools->all());
        $this->assertStringContainsString('Always report temperatures in Celsius.', ExecutionTestFactory::agentResources($agent)->instructions->getContent());
    }

    #[DataProvider('invalidTools')]
    public function test_adding_a_list_with_an_invalid_entry_adds_none_of_it(mixed $invalidTool): void
    {
        $agent = (new WeatherAgent())->setAiProvider(new \NeuronAI\Testing\FakeAIProvider());
        $before = $agent->getTools();

        try {
            $agent->addTool([new SearchTool(), $invalidTool]);
            $this->fail('Expected the invalid entry to be rejected.');
        } catch (AgentException $exception) {
            $this->assertSame('Tools must be an instance of ToolInterface, ToolkitInterface, or ProviderToolInterface', $exception->getMessage());
        }

        $this->assertEquals($before, $agent->getTools());
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
        $agent = (new WeatherAgent())->setAiProvider(new \NeuronAI\Testing\FakeAIProvider());
        $search = new SearchTool();
        $agent->addTool($search);
        $tools = ExecutionTestFactory::agentResources($agent)->tools->all();

        try {
            $agent->setTools([new ProviderTool('web_search'), $invalidTool]);
            $this->fail('Expected invalid tools to be rejected.');
        } catch (AgentException $exception) {
            $this->assertSame('Tools must be an instance of ToolInterface, ToolkitInterface, or ProviderToolInterface', $exception->getMessage());
        }

        $this->assertSame($search, $agent->getTools()[0]);
        $this->assertInstanceOf(WeatherToolkit::class, $agent->getTools()[1]);
        $this->assertEquals($tools, ExecutionTestFactory::agentResources($agent)->tools->all());
    }
}
