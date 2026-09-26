<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class AzureOpenAIConstructionTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_chat_is_sent_to_the_deployment_with_the_api_version_query(): void
    {
        $provider = new AzureOpenAI(
            key: 'azure-key',
            endpoint: 'https://my-resource.openai.azure.com/',
            model: 'gpt-4o-deployment',
            version: '2024-10-21',
            httpClient: $this->recordingClient(new Response(200, body: '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer"}}]}')),
        );

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(
            ['POST https://my-resource.openai.azure.com/openai/deployments/gpt-4o-deployment/chat/completions?api-version=2024-10-21'],
            $this->sentTargets(),
        );
    }
}
