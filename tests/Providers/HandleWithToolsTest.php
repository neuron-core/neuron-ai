<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\DeferredTool;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class HandleWithToolsTest extends TestCase
{
    public function test_calls_record_execution_type_from_the_registered_definition(): void
    {
        $provider = new FakeAIProvider();
        $provider->setTools([
            new ToolStub('local'),
            new DeferredTool('browser', 'Read the page title'),
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
}
