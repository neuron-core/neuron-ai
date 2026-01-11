<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
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
        string $key,
        string $model,
        protected string $baseUri = 'https://api.cohere.ai/v2',
        array $parameters = [],
        bool $strict_response = false,
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

    public function structured(Message|array $messages, string $class, array $response_format): Message
    {
        $this->parameters = array_replace_recursive($this->parameters, [
            'response_format' => [
                'type' => 'json_object',
                'json_schema' => $response_format,
            ]
        ]);

        $messages = is_array($messages) ? $messages : [$messages];
        $message = end($messages);
        $message->addContent(new TextContent('Generate a JSON'));

        return $this->chat($messages);
    }
}
