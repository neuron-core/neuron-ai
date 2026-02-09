<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;

use function array_unshift;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => false,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (! empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'chat',
                body: $json
            )
        );

        if (!$response->isSuccessful()) {
            throw new ProviderException("Ollama chat error: {$response->body}");
        }

        return $this->processResponse($response->json());
    }

    /**
     * @throws ProviderException
     */
    protected function processResponse(array $response): AssistantMessage
    {
        $message = $response['message'];

        if (isset($message['tool_calls'])) {
            $message = $this->createToolCallMessage($message['tool_calls'], $message['content'] ?? null);
        } else {
            $message = new AssistantMessage($message['content']);
        }

        if (isset($response['prompt_eval_count']) && isset($response['eval_count'])) {
            $message->setUsage(
                new Usage($response['prompt_eval_count'], $response['eval_count'])
            );
        }

        if (isset($response['done_reason'])) {
            $message->setStopReason($response['done_reason']);
        }

        return $message;
    }
}
