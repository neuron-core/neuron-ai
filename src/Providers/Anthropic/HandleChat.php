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
        $json = $this->requestBody($messages);

        // Add cache_control to last tool if caching is enabled
        if (!empty($this->tools) && $this->promptCachingEnabled) {
            $last = count($json['tools']) - 1;
            $json['tools'][$last]['cache_control'] = ['type' => 'ephemeral'];
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: $this->requestUri(false),
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
        $toolPositions = [];
        $redactedThinking = [];

        if (!isset($result['content'])) {
            goto message;
        }

        foreach ($result['content'] as $index => $content) {
            if ($content['type'] === 'thinking') {
                $blocks[] = new ReasoningContent($content['thinking'], $content['signature']);
                continue;
            }

            if ($content['type'] === 'text') {
                $blocks[] = new TextContent($content['text']);
                continue;
            }

            if ($content['type'] === 'redacted_thinking') {
                $redactedThinking[$index] = $content['data'];
                continue;
            }

            if ($content['type'] === 'tool_use') {
                $toolCalls[] = $content;
                $toolPositions[] = $index;
            }
        }

        message:
        if ($toolCalls !== []) {
            $message = $this->createToolCallMessage($toolCalls, $blocks);
        } else {
            $message = new AssistantMessage($blocks);
            $citations = $this->extractCitations($result['content'] ?? []);
            if (!empty($citations)) {
                $message->addMetadata('citations', $citations);
            }
        }

        if ($redactedThinking !== []) {
            $message->addMetadata('anthropic_redacted_thinking', $redactedThinking);
        }
        if ($toolPositions !== []) {
            $message->addMetadata('anthropic_tool_positions', $toolPositions);
        }

        // Save the usage for the current interaction
        if (isset($result['usage'])) {
            $usage = $result['usage'];

            // Attach Anthropic-specific cache metrics as metadata (supports both API formats)
            $cacheCreation = $usage['cache_creation'] ?? [];
            $cacheWrite = ($cacheCreation['ephemeral_5m_input_tokens'] ?? 0)
                        + ($cacheCreation['ephemeral_1h_input_tokens'] ?? 0)
                        + ($usage['cache_creation_input_tokens'] ?? 0);
            $cacheRead = $usage['cache_read_input_tokens'] ?? 0;

            // Anthropic reports cache reads separately from `input_tokens`;
            // surface the cache-read count as the standard cached metric.
            $message->setUsage(new Usage($usage['input_tokens'], $usage['output_tokens'], $cacheRead));

            if ($cacheWrite > 0 || $cacheRead > 0) {
                $message->addMetadata('cacheWriteTokens', (string) $cacheWrite)
                    ->addMetadata('cacheReadTokens', (string) $cacheRead);
            }
        }

        if (isset($result['stop_reason'])) {
            $message->setStopReason($result['stop_reason']);
        }

        return $message;
    }
}
