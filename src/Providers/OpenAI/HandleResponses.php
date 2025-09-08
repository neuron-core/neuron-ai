<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
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
                $result = \json_decode($response->getBody()->getContents(), true);

                $functions = \array_filter($result['output'], fn (array $message): bool => $message['type'] == 'function_call');

                if ($functions !== []) {
                    $response = $this->createToolCallMessage($functions);
                } else {
                    // Keep only the assistant response part
                    $messages = \array_values(
                        \array_filter(
                            $result['output'],
                            fn (array $message): bool => $message['type'] == 'message' && $message['role'] == MessageRole::ASSISTANT->value
                        )
                    );

                    $content = $messages[0]['content'][0];

                    $response = new AssistantMessage($content['text']);

                    $response->addMetadata('id', $messages[0]['id']);

                    if (isset($content['annotations'])) {
                        $response->addMetadata('annotations', $content['annotations']);
                    }

                    /*foreach ($content['annotations'] ?? [] as $annotation) {
                        if ($annotation['type'] === 'url_citation') {
                            $response->addAnnotation(
                                new Annotation(
                                    url: $annotation['url'],
                                    title: $annotation['title'],
                                    startIndex: $annotation['start_index'],
                                    endIndex: $annotation['end_index'],
                                )
                            );
                        }
                    }*/
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
