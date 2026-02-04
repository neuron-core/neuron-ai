<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\Message;
use Psr\Http\Message\ResponseInterface;

use function array_filter;
use function array_map;
use function implode;
use function json_decode;

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

        // Attach metadata
        $latestMessage = end($messages);
        if (!empty($latestMessage->meta)) {
            $json['metadata'] = $latestMessage->getMetadata();
        }

        return $this->client->postAsync('responses', [RequestOptions::JSON => $json])
            ->then(function (ResponseInterface $response) {
                $response = json_decode($response->getBody()->getContents(), true);

                $toolCalls = array_filter($response['output'], fn (array $item): bool => $item['type'] == 'function_call');

                if ($toolCalls !== []) {
                    // Extract text content from output_text items
                    $textItems = array_filter($response['output'], fn (array $item): bool => $item['type'] == 'output_text');
                    $text = implode('', array_map(fn (array $item): string => $item['text'] ?? '', $textItems));

                    return $this->createToolCallMessage($toolCalls, $text, $response['usage'] ?? null);
                }

                return $this->createAssistantMessage($response);
            });
    }
}
