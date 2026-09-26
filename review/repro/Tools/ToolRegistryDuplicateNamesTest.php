<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

use function array_map;

class ToolRegistryDuplicateNamesTest extends TestCase
{
    public function test_constructor_applies_the_same_unique_name_rule_as_add(): void
    {
        $first = new ToolStub('lookup');

        $constructed = new ToolRegistry([$first, new ToolStub('lookup')]);

        $added = new ToolRegistry();
        $added->add($first);
        $added->add(new ToolStub('lookup'));

        $this->assertSame($added->all(), $constructed->all());
    }

    public function test_agent_never_offers_the_provider_two_tools_with_the_same_name(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('done'));

        Agent::make()
            ->setAiProvider($provider)
            ->setMessageStore(new InMemoryMessageStore())
            ->setThreadId('duplicate-tools')
            ->addTool([new ToolStub('lookup'), new ToolStub('lookup')])
            ->chat(new UserMessage('hi'))
            ->getMessage();

        $offered = array_map(
            static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(),
            $provider->getRecorded()[0]->tools,
        );

        $this->assertSame(['lookup'], $offered);
    }
}
