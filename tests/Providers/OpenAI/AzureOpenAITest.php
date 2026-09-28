<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\AzureOpenAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class AzureOpenAITest extends TestCase
{
    use RecordsHttpRequests;

    protected const RESPONSE = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer"}}]}';

    #[TestWith(['https://my-resource.openai.azure.com/'])]
    #[TestWith(['https://my-resource.openai.azure.com'])]
    #[TestWith(['my-resource.openai.azure.com'])]
    public function test_chat_is_sent_to_the_v1_api_with_the_deployment_as_model(string $endpoint): void
    {
        $provider = new AzureOpenAI('azure-key', $endpoint, 'gpt-4o-deployment', httpClient: $this->recordingClient(new Response(200, body: self::RESPONSE)));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://my-resource.openai.azure.com/openai/v1/chat/completions'], $this->sentTargets());
        $this->assertSame('gpt-4o-deployment', json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR)['model']);
        $this->assertSame('Answer', $message->getContent());
    }

    public function test_the_resource_key_is_sent_in_the_api_key_header_not_as_a_bearer_token(): void
    {
        $provider = new AzureOpenAI('azure-key', 'https://my-resource.openai.azure.com', 'gpt-4o-deployment', httpClient: $this->recordingClient(new Response(200, body: self::RESPONSE)));

        $provider->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame('azure-key', $request->getHeaderLine('api-key'));
        $this->assertFalse($request->hasHeader('Authorization'));
    }
}
