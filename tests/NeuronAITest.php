<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use const PHP_EOL;

class NeuronAITest extends TestCase
{
    public function test_system_instructions(): void
    {
        $system = new SystemPrompt(["Agent"]);
        $this->assertEquals("# IDENTITY AND PURPOSE".PHP_EOL."Agent", $system);

        $agent = new class () extends Agent {
            public function instructions(): string
            {
                return 'Hello';
            }
        };
        $this->assertEquals('Hello', $agent->getInstructions()->getContent());
        $agent->setInstructions('Hello2');
        $this->assertEquals('Hello2', $agent->getInstructions()->getContent());
    }

    public function test_static_constructor_builds_the_called_subclass(): void
    {
        $subclass = new class () extends FakeAIProvider {
        };

        $made = $subclass::make();

        $this->assertInstanceOf($subclass::class, $made);
        $this->assertNotSame($subclass, $made);
    }

    public function test_static_constructor_forwards_positional_arguments(): void
    {
        $first = new AssistantMessage('first');
        $second = new AssistantMessage('second');

        $provider = FakeAIProvider::make($first, $second);

        $this->assertSame($first, $provider->chat(new UserMessage('a'))->message());
        $this->assertSame($second, $provider->chat(new UserMessage('b'))->message());
    }

    public function test_static_constructor_forwards_named_arguments(): void
    {
        $call = ToolCall::make('search', inputs: ['query' => 'php'], callId: 'call_1');

        $this->assertSame('search', $call->getName());
        $this->assertSame('call_1', $call->getCallId());
        $this->assertSame(['query' => 'php'], $call->getInputs());
    }
}
