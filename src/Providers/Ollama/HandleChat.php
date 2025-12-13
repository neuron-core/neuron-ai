<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HttpClient\HttpException;
use NeuronAI\Providers\HttpClient\HttpRequest;

use function array_unshift;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(array $messages): Message
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

        $request = HttpRequest::post('chat', $json);
        $response = $this->httpClient->request($request);

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

        return $message;
    }
}
