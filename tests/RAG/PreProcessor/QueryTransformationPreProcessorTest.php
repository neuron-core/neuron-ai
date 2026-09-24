<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PreProcessor;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use PHPUnit\Framework\TestCase;

class QueryTransformationPreProcessorTest extends TestCase
{
    public function test_instance(): void
    {
        $processor = new QueryTransformationPreProcessor(
            provider: new Anthropic(
                'key',
                'model'
            )
        );

        $this->assertInstanceOf(QueryTransformationPreProcessor::class, $processor);
        $this->assertInstanceOf(PreProcessorInterface::class, $processor);
    }

    public function test_override_instructions_constructor(): void
    {
        $prompt = new SystemPrompt(
            background: ['background'],
            steps: ['steps'],
            output: ['output'],
        );

        $processor = new QueryTransformationPreProcessor(
            new Anthropic(
                'key',
                'model'
            ),
            customPrompt: (string) $prompt
        );

        $this->assertEquals($prompt, $processor->getSystemPrompt());
    }

    public function test_override_instructions_setter(): void
    {
        $prompt = new SystemPrompt(
            background: ['background'],
            steps: ['steps'],
            output: ['output'],
        );

        $processor = new QueryTransformationPreProcessor(
            new Anthropic(
                'key',
                'model'
            )
        );

        $processor->setCustomPrompt((string) $prompt);

        $this->assertEquals($prompt, $processor->getSystemPrompt());
    }

    public function test_the_rewrite_request_offers_no_tools(): void
    {
        // A provider shared with the agent still holds the tools of its last inference.
        $provider = new FakeAIProvider(new AssistantMessage('Rewritten query'));
        $provider->setTools([new SearchTool()]);

        (new QueryTransformationPreProcessor($provider))->process(new UserMessage('Original query'));

        $provider->assertCallCount(1);
        $this->assertSame([], $provider->getRecorded()[0]->tools);
    }
}
