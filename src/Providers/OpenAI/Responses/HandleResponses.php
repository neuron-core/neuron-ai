<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\Message;
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
                $response = \json_decode($response->getBody()->getContents(), true);

                $toolCalls = \array_filter($response['output'], fn (array $item): bool => $item['type'] == 'function_call');

                if ($toolCalls !== []) {
                    return $this->createToolCallMessage($toolCalls, $response['usage'] ?? null);
                }

                return $this->createAssistantMessage($response);
            });
    }
}
