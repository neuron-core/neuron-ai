<?php

namespace NeuronAI\Providers\Ollama;

use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;

trait HandleChat
{
    public function chat(array $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array $messages): PromiseInterface
    {
        // Include the system prompt
        if (isset($this->system)) {
            \array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => false,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (! empty($this->tools)) {
            $json['tools'] = $this->generateToolsPayload();
        }

        return $this->client->postAsync('chat', compact('json'))
            ->then(function ($response) {
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new ProviderException("Ollama chat error: {$response->getBody()->getContents()}");
                }

                $response = json_decode($response->getBody()->getContents(), true);
                $message = $response['message'];

                if (\array_key_exists('tool_calls', $message)) {
                    $message = $this->createToolCallMessage($message);
                } else {
                    $message = new AssistantMessage($message['content']);
                }

                if (\array_key_exists('prompt_eval_count', $response) && \array_key_exists('eval_count', $response)) {
                    $message->setUsage(
                        new Usage($response['prompt_eval_count'], $response['eval_count'])
                    );
                }

                return $message;
            });
    }
}
