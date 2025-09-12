<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use Psr\Http\Message\ResponseInterface;

/**
 * Inspired by Andrew Monty - https://github.com/AndrewMonty
 */
trait HandleResponses
{
    public function chat(array $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array $messages): PromiseInterface
    {
        $json = [
            'model' => $this->model,
            'input' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach the system prompt
        if (isset($this->system)) {
            $json['instructions'] = $this->system;
        }

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->client->postAsync('responses', [RequestOptions::JSON => $json])
            ->then(function (ResponseInterface $response) {
                $result = \json_decode($response->getBody()->getContents(), true);

                $functions = \array_filter($result['output'], fn (array $message): bool => $message['type'] == 'function_call');

                if ($functions !== []) {
                    $response = $this->createToolCallMessage($functions);
                } else {
                    // Keep only the assistant response part
                    $messages = \array_values(
                        \array_filter(
                            $result['output'],
                            fn (array $message): bool => $message['type'] === 'message' && $message['role'] == MessageRole::ASSISTANT->value
                        )
                    );

                    $content = $messages[0]['content'][0];

                    $response = new AssistantMessage($content['text']);

                    // todo: refactor after implementing citations abstraction
                    if (isset($content['annotations'])) {
                        $response->addMetadata('annotations', $content['annotations']);
                    }
                }

                if (\array_key_exists('usage', $result)) {
                    $response->setUsage(
                        new Usage($result['usage']['input_tokens'], $result['usage']['output_tokens'])
                    );
                }

                return $response;
            });
    }
}
