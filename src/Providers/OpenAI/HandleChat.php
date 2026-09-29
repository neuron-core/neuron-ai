<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ProviderResponse;

use function array_unshift;
use function uniqid;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): ProviderResponse
    {
        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $body = [
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->request(
            $this->createChatHttpRequest($body)
        );

        return new ProviderResponse(
            message: $this->processChatResult($response->json()),
            body: $response->body,
            headers: $response->headers,
        );
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        // The calls decide, not the finish reason: a forced tool_choice answers "stop"
        if (!empty($result['choices'][0]['message']['tool_calls'])) {
            $block = isset($result['choices'][0]['message']['content'])
                ? new TextContent($result['choices'][0]['message']['content'])
                : null;
            $response = $this->createToolCallMessage($result['choices'][0]['message']['tool_calls'], $block);
        } else {
            $response = $this->createAssistantMessage($result['choices'][0]['message']);
        }

        if (isset($result['usage'])) {
            $response->setUsage(
                new Usage(
                    $result['usage']['prompt_tokens'] ?? 0,
                    $result['usage']['completion_tokens'] ?? 0,
                    $result['usage']['prompt_tokens_details']['cached_tokens'] ?? 0,
                    $result['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0,
                )
            );
        }

        // Extract citations from content annotations
        $citations = $this->extractCitations($result['choices'][0]['message']);
        if (!empty($citations)) {
            $response->addMetadata('citations', $citations);
        }

        $response->setStopReason($result['choices'][0]['finish_reason']);

        return $this->enrichMessage($response, $result);
    }

    protected function createAssistantMessage(array $message): AssistantMessage
    {
        return new AssistantMessage($message['content']);
    }

    /**
     * Chat Completions annotates the message itself, with url_citation only.
     *
     * @return Citation[]
     */
    protected function extractCitations(array $message): array
    {
        $citations = [];

        foreach ($message['annotations'] ?? [] as $annotation) {
            if (($annotation['type'] ?? null) !== 'url_citation') {
                continue;
            }

            $urlCitation = $annotation['url_citation'] ?? [];
            $citations[] = new Citation(
                id: uniqid('openai_url_'),
                source: $urlCitation['url'] ?? '',
                title: $urlCitation['title'] ?? null,
                startIndex: $urlCitation['start_index'] ?? null,
                endIndex: $urlCitation['end_index'] ?? null,
                metadata: [
                    'type' => 'url_citation',
                    'provider' => 'openai',
                ]
            );
        }

        return $citations;
    }
}
