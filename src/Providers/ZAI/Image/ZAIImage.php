<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ZAI\Image;

use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

use function end;

class ZAIImage implements AIProviderInterface
{
    use HasHttpClient;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://api.z.ai/api/paas/v4';

    /**
     * System instructions.
     */
    protected ?string $system = null;

    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]);
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    /**
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        $message = end($messages);

        if ($this->system ?? false) {
            $message->addContent(new TextContent($this->system));
        }

        $body = [
            'model' => $this->model,
            'prompt' => $message->getContent(),
            ...$this->parameters
        ];

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'images/generations',
                body: $body
            )
        )->json();

        $result = new AssistantMessage(
            new ImageContent($response['data'][0]['url'], SourceType::URL)
        );

        if ($response['usage']) {
            $result->setUsage(
                new Usage(
                    $response['usage']['prompt_tokens'],
                    $response['usage']['completion_tokens']
                )
            );
        }

        return $result;
    }

    public function stream(Message ...$messages): Generator
    {
        throw new ProviderException('Streaming not supported for image generation. Use chat() instead.');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        throw new ProviderException('Structured output not supported for image generation. Use chat() instead.');
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new ProviderException('Messages are not supported for image generation.');
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new ProviderException('Tools are not supported for image generation.');
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }
}
