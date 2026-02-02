<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;

use function is_array;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(array|Message $messages): Message
    {
        $messages = is_array($messages) ? $messages : [$messages];

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

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'messages',
                body: $json
            )
        );

        return $this->processChatResult($response->json());
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        $blocks = [];
        $toolCalls = [];

        if (!isset($result['content'])) {
            goto message;
        }

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

        message:
        if ($toolCalls !== []) {
            $message = $this->createToolCallMessage($toolCalls, $blocks);
        } else {
            $message = new AssistantMessage($blocks);
            $citations = $this->extractCitations($result['content']);
            if (!empty($citations)) {
                $message->addMetadata('citations', $citations);
            }
        }

        // Save the usage for the current interaction
        if (isset($result['usage'])) {
            $message->setUsage(
                new Usage(
                    $result['usage']['input_tokens'],
                    $result['usage']['output_tokens']
                )
            );
        }

        if (isset($result['stop_reason'])) {
            $message->setStopReason($result['stop_reason']);
        }

        return $message;
    }
}
