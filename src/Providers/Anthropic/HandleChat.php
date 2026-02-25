<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;

use function count;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        $json = [
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (isset($this->system)) {
            $json['system'] = $this->system;
        } elseif (isset($this->systemBlocks)) {
            $json['system'] = $this->systemBlocks;
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);

            // Add cache_control to last tool if caching is enabled
            if ($this->promptCachingEnabled) {
                $last = count($json['tools']) - 1;
                $json['tools'][$last]['cache_control'] = ['type' => 'ephemeral'];
            }
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
            $u = $result['usage'];

            // Extract cache metrics (supports both API formats)
            $cacheCreation = $u['cache_creation'] ?? [];
            $cacheWrite = ($cacheCreation['ephemeral_5m_input_tokens'] ?? 0)
                        + ($cacheCreation['ephemeral_1h_input_tokens'] ?? 0)
                        + ($u['cache_creation_input_tokens'] ?? 0);
            $cacheRead = $u['cache_read_input_tokens'] ?? 0;

            $message->setUsage(
                new Usage(
                    $u['input_tokens'],
                    $u['output_tokens'],
                    $cacheWrite,
                    $cacheRead
                )
            );
        }

        if (isset($result['stop_reason'])) {
            $message->setStopReason($result['stop_reason']);
        }

        return $message;
    }
}
