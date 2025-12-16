<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;

use function array_unshift;

trait HandleStream
{
    /**
     * @throws HttpException
     */
    public function stream(array|string $messages): Generator
    {
        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->stream(
            HttpRequest::post(
                uri: 'chat',
                body: $json
            )
        );

        yield from $this->stream($response);
    }

    protected function processStream(StreamInterface $stream): Generator
    {

    }
}
