<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use Psr\Http\Message\ResponseInterface;

trait HandleChat
{
    public function chat(array $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array $messages): PromiseInterface
    {
        $json = [
            'contents' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        if (isset($this->system)) {
            $json['system_instruction'] = [
                'parts' => [
                    ['text' => $this->system]
                ]
            ];
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->client->postAsync(\trim($this->baseUri, '/')."/{$this->model}:generateContent", [RequestOptions::JSON => $json])
            ->then(function (ResponseInterface $response): Message {
                $result = \json_decode($response->getBody()->getContents(), true);

                $content = $result['candidates'][0]['content'];

                if (!isset($content['parts']) && isset($result['candidates'][0]['finishReason']) && $result['candidates'][0]['finishReason'] === 'MAX_TOKENS') {
                    return new Message(MessageRole::from($content['role']), '');
                }

                $parts = $content['parts'];

                if (\array_key_exists('functionCall', $parts[0]) && !empty($parts[0]['functionCall'])) {
                    $response = $this->createToolCallMessage($content);
                } else {
                    $response = new Message(MessageRole::from($content['role']), $parts[0]['text'] ?? '');
                }

                if (\array_key_exists('groundingMetadata', $result['candidates'][0])) {
                    $response->addMetadata('groundingMetadata', $result['candidates'][0]['groundingMetadata']);
                }

                // Attach the usage for the current interaction
                if (\array_key_exists('usageMetadata', $result)) {
                    $response->setUsage(
                        new Usage(
                            $result['usageMetadata']['promptTokenCount'],
                            $result['usageMetadata']['candidatesTokenCount'] ?? 0
                        )
                    );
                }

                return $response;
            });
    }
}
