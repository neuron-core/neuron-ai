<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use NeuronAI\Tests\Providers\OpenAI\Responses\Stub\InspectableOpenAIResponses;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class OpenAIResponsesPromptCachingTest extends TestCase
{
    public function test_uncached_system_prompt_uses_top_level_instructions(): void
    {
        $provider = new InspectableOpenAIResponses('', 'gpt-5.6');
        $provider->systemPrompt(new SystemMessage('Stable instructions'));

        $body = $provider->buildRequestBody([new UserMessage('Question')]);

        $this->assertSame('Stable instructions', $body['instructions']);
        $this->assertArrayNotHasKey('prompt_cache_options', $body);
        $this->assertSame('user', $body['input'][0]['role']);
    }

    public function test_cached_system_block_becomes_an_explicit_cache_breakpoint(): void
    {
        $provider = new InspectableOpenAIResponses('', 'gpt-5.6', [
            'prompt_cache_options' => ['ttl' => '30m'],
        ]);
        $provider->systemPrompt(new SystemMessage([
            (new SystemContent('Stable instructions'))->cache(),
            new SystemContent('Dynamic RAG context'),
        ]));

        $body = $provider->buildRequestBody([new UserMessage('Question')]);

        $this->assertArrayNotHasKey('instructions', $body);
        $this->assertSame([
            'ttl' => '30m',
            'mode' => 'explicit',
        ], $body['prompt_cache_options']);
        $this->assertSame('developer', $body['input'][0]['role']);
        $this->assertSame([
            'type' => 'input_text',
            'text' => 'Stable instructions',
            'prompt_cache_breakpoint' => ['mode' => 'explicit'],
        ], $body['input'][0]['content'][0]);
        $this->assertSame([
            'type' => 'input_text',
            'text' => 'Dynamic RAG context',
        ], $body['input'][0]['content'][1]);
        $this->assertSame('user', $body['input'][1]['role']);
    }

    public function test_streaming_uses_the_same_cached_prompt_payload(): void
    {
        $provider = new InspectableOpenAIResponses('', 'gpt-5.6');
        $provider->systemPrompt((new SystemMessage('Stable instructions'))->cache());

        $body = $provider->buildRequestBody([new UserMessage('Question')], true);

        $this->assertTrue($body['stream']);
        $this->assertSame(
            ['mode' => 'explicit'],
            $body['input'][0]['content'][0]['prompt_cache_breakpoint'],
        );
    }
}
