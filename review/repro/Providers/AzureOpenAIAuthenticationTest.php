<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class AzureOpenAIAuthenticationTest extends TestCase
{
    use RecordsHttpRequests;

    protected function provider(HttpClientInterface $httpClient): AzureOpenAI
    {
        // The anonymous subclass only supplies the deployment URL template the framework
        // expects, so the constructor defect does not mask the authentication header.
        return new class ('azure-resource-key', 'https://my-resource.openai.azure.com', 'gpt-4o', '2024-10-21', httpClient: $httpClient) extends AzureOpenAI {
            protected string $baseUri = 'https://%s/openai/deployments/%s';
        };
    }

    public function test_resource_key_is_sent_in_the_api_key_header_not_as_a_bearer_token(): void
    {
        $answer = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Hi"}}]}';

        $this->provider($this->recordingClient(new Response(200, body: $answer)))->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame('azure-resource-key', $request->getHeaderLine('api-key'));
        $this->assertFalse($request->hasHeader('Authorization'));
    }
}
