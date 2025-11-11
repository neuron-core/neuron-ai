<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
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

                $blocks = [];
                foreach ($content['parts'] as $part) {
                    if (isset($part['text'])) {
                        $blocks[] = $part['thought'] ?? false
                            ? new ReasoningContent($part['text'])
                            : new TextContent($part['text']);
                    }

                    if (isset($part['inlineData'])) {
                        $blocks[] = new ImageContent(
                            $part['inlineData']['data'],
                            SourceType::BASE64,
                            'image/png'
                        );
                    }

                    if (isset($part['functionCall'])) {
                        $message = $this->createToolCallMessage($content);
                        $message->setContents($blocks);
                    }
                }

                if (!isset($message)) {
                    $message = new AssistantMessage($blocks);
                }

                if (\array_key_exists('groundingMetadata', $result['candidates'][0])) {
                    // Extract citations from groundingMetadata
                    $citations = $this->extractCitations($result['candidates'][0]['groundingMetadata']);
                    if (!empty($citations)) {
                        $message->addMetadata('citations', $citations);
                    }
                }

                // Attach the usage for the current interaction
                if (\array_key_exists('usageMetadata', $result)) {
                    $message->setUsage(
                        new Usage(
                            $result['usageMetadata']['promptTokenCount'],
                            $result['usageMetadata']['candidatesTokenCount'] ?? 0
                        )
                    );
                }

                return $message;
            });
    }
}
