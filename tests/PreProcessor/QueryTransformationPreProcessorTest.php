<?php

declare(strict_types=1);

namespace NeuronAI\Tests\PreProcessor;

use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use NeuronAI\Agent\SystemPrompt;
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

}
