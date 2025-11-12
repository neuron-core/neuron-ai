<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\AssistantMessage;
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
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (isset($this->system)) {
            $json['system'] = $this->system;
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->client->postAsync('messages', [RequestOptions::JSON => $json])
            ->then(onFulfilled: function (ResponseInterface $response): Message {
                $result = \json_decode($response->getBody()->getContents(), true);

                $blocks = [];
                $toolCalls = [];
                foreach ($result['content'] as $content) {
                    if ($content['type'] === 'thinking') {
                        $blocks[] = new ReasoningContent($content['thinking'], $content['signature']);
                        continue;
                    }

                    if ($content['type'] === 'text') {
                        $blocks[] = new TextContent($content['text']);
                        continue;
                    }

                    if ($content['type'] === 'tool_use') {
                        $toolCalls[] = $content;
                    }
                }

                if ($toolCalls !== []) {
                    $message = $this->createToolCallMessage($toolCalls, $blocks);
                } else {
                    $message = new AssistantMessage($blocks);
                    $citations = $this->extractCitations($result['content']);
                    if (!empty($citations)) {
                        $message->addMetadata('citations', $citations);
                    }
                }

                // Attach the usage for the current interaction
                if (\array_key_exists('usage', $result)) {
                    $message->setUsage(
                        new Usage(
                            $result['usage']['input_tokens'],
                            $result['usage']['output_tokens']
                        )
                    );
                }

                return $message;
            });
    }
}
