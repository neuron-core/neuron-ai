<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

class HandleWithToolsTest extends TestCase
{
    protected function provider(ToolInterface|ProviderToolInterface ...$tools): OpenAI
    {
        $provider = new OpenAI('key', 'model');
        $provider->setTools($this->registry(...$tools));

        return $provider;
    }

    /**
     * The registry holds local and provider tools alike.
     */
    protected function registry(ToolInterface|ProviderToolInterface ...$tools): array
    {
        return $tools;
    }

    public function test_calls_record_execution_type_from_the_registered_definition(): void
    {
        $provider = new FakeAIProvider();
        $provider->setTools([
            new ToolStub('local'),
            new FrontendTool('browser', 'Read the page title'),
        ]);

        $local = $provider->newToolCall('local', 'local-call', []);
        $deferred = $provider->newToolCall('browser', 'browser-call', ['selector' => 'title']);

        $this->assertFalse($local->isDeferred());
        $this->assertFalse($local->hasResult());
        $this->assertTrue($deferred->isDeferred());
        $this->assertFalse($deferred->hasResult());
        $this->assertSame('Read the page title', $deferred->getDescription());
        $this->assertSame(['selector' => 'title'], $deferred->getInputs());

        $deferred->setResult('Page title');
        $this->assertTrue($deferred->isDeferred());
        $this->assertTrue($deferred->hasResult());
    }

    public function test_new_tool_call_keeps_the_name_and_call_id_requested_by_the_model(): void
    {
        $provider = $this->provider(new ToolStub('lookup', 'Look something up'));

        $call = $provider->newToolCall('lookup', 'call_1', ['query' => 'Rome']);

        $this->assertSame('lookup', $call->getName());
        $this->assertSame('call_1', $call->getCallId());
        $this->assertSame(['query' => 'Rome'], $call->getInputs());
        $this->assertSame('Look something up', $call->getDescription());
    }

    public function test_unknown_tool_name_is_rejected_with_the_requested_name(): void
    {
        $provider = $this->provider(new ToolStub('lookup'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: delete_everything.');

        $provider->newToolCall('delete_everything', 'call_1', []);
    }

    public function test_tool_names_are_matched_exactly(): void
    {
        $provider = $this->provider(new ToolStub('lookup'));

        foreach (['Lookup', 'lookup ', ' lookup', 'look'] as $name) {
            try {
                $provider->findTool($name);
                $this->fail("'{$name}' must not resolve to the 'lookup' tool.");
            } catch (ProviderException $exception) {
                $this->assertStringContainsString("non-existing tool: {$name}.", $exception->getMessage());
            }
        }
    }

    public function test_provider_tools_cannot_be_resolved_as_local_tools(): void
    {
        $provider = $this->provider(new ProviderTool('web_search', 'web_search'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: web_search.');

        $provider->findTool('web_search');
    }

    public function test_find_tool_returns_an_independent_copy_for_each_call(): void
    {
        $registered = new ToolStub('lookup');
        $provider = $this->provider($registered);

        $first = $provider->findTool('lookup');
        $first->setResult('first result');
        $second = $provider->findTool('lookup');

        $this->assertNotSame($registered, $first);
        $this->assertNotSame($first, $second);
        $this->assertFalse($second->hasResult());
        $this->assertFalse($registered->hasResult());
    }

    public function test_set_tools_replaces_the_previous_registry(): void
    {
        $provider = $this->provider(new ToolStub('old'));
        $provider->setTools([new ToolStub('new')]);

        $this->assertSame('new', $provider->findTool('new')->getName());
        $this->expectException(ProviderException::class);
        $provider->findTool('old');
    }
}
