<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use Psr\Http\Message\ResponseInterface;

use function array_unshift;
use function is_array;
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
            ->then(function (ResponseInterface $response): AssistantMessage|ToolCallMessage {
                $result = json_decode($response->getBody()->getContents(), true);

                if ($result['choices'][0]['finish_reason'] === 'tool_calls') {
                    // createToolCallMessage already calls enrichMessage
                    $response = $this->createToolCallMessage(
                        $result['choices'][0]['message']['tool_calls'],
                        new TextContent($result['choices'][0]['message']['content'])
                    );
                } else {
                    $response = $this->enrichMessage(
                        new AssistantMessage($response['choices'][0]['message']['content']),
                        $result
                    );
                }

                if (isset($result['usage'])) {
                    $response->setUsage(
                        new Usage($result['usage']['prompt_tokens'], $result['usage']['completion_tokens'])
                    );
                }

                // Extract citations from content annotations
                $message = $result['choices'][0]['message'];
                if (isset($message['content']) && is_array($message['content'])) {
                    foreach ($message['content'] as $contentBlock) {
                        if (isset($contentBlock['annotations']) && is_array($contentBlock['annotations'])) {
                            $citations = $this->extractCitations($contentBlock['annotations']);
                            if (!empty($citations)) {
                                $response->addMetadata('citations', $citations);
                            }
                        }
                    }
                }

                return $response;
            });
    }
}
