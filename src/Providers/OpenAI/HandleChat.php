<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use Psr\Http\Message\ResponseInterface;

use function array_key_exists;
use function array_unshift;
use function json_decode;

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
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->client->postAsync('chat/completions', [RequestOptions::JSON => $json])
            ->then(function (ResponseInterface $response) {
                $result = json_decode($response->getBody()->getContents(), true);

                if ($result['choices'][0]['finish_reason'] === 'tool_calls') {
                    $response = $this->createToolCallMessage($result['choices'][0]['message']);
                } else {
                    $response = $this->createAssistantMessage($result);
                }

                // Attach the stop reason
                $response->setStopReason($result['choices'][0]['finish_reason'] ?? '');

                if (array_key_exists('usage', $result)) {
                    $response->setUsage(
                        new Usage($result['usage']['prompt_tokens'], $result['usage']['completion_tokens'])
                    );
                }

                return $response;
            });
    }

    protected function createAssistantMessage(array $response): AssistantMessage
    {
        return new AssistantMessage($response['choices'][0]['message']['content']);
    }
}
