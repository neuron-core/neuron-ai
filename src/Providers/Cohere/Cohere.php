<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * https://docs.cohere.com/reference/chat
 */
class Cohere extends OpenAI
{
    use HandleChat;
    use HandleStream;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected string $baseUri = 'https://api.cohere.ai/v2',
        protected array $parameters = [],
        protected bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($key, $model, $parameters, $strict_response, $httpClient);
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper();
    }

    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        unset($payload['stream_options']);

        return HttpRequest::post(
            uri: 'chat',
            body: $payload
        );
    }
}
