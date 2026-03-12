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

use function array_unshift;
use function is_array;
use function uniqid;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $body = [
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->request(
            $this->createChatHttpRequest($body)
        );

        return $this->processChatResult($response->json());
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        if ($result['choices'][0]['finish_reason'] === 'tool_calls') {
            $block = isset($result['choices'][0]['message']['content'])
                ? new TextContent($result['choices'][0]['message']['content'])
                : null;
            $response = $this->createToolCallMessage($result['choices'][0]['message']['tool_calls'], $block);
        } else {
            $response = $this->createAssistantMessage($result['choices'][0]['message']);
        }

        if (isset($result['usage'])) {
            $response->setUsage(
                new Usage($result['usage']['prompt_tokens'], $result['usage']['completion_tokens'])
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
     * Extract citations from OpenAI's content annotations.
     *
     * @return Citation[]
     */
    protected function extractCitations(array $message): array
    {
        $citations = [];

        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $contentBlock) {
                if (isset($contentBlock['annotations']) && is_array($contentBlock['annotations'])) {
                    foreach ($contentBlock['annotations'] as $annotation) {
                        if ($citation = $this->processAnnotation($annotation)) {
                            $citations[] = $citation;
                        }
                    }
                }
            }
        }

        return $citations;
    }

    protected function processAnnotation(array $annotation): ?Citation
    {
        $type = $annotation['type'] ?? null;
        if ($type === 'file_citation') {
            $fileCitation = $annotation['file_citation'] ?? [];
            return new Citation(
                id: $fileCitation['file_id'] ?? uniqid('openai_file_'),
                source: $fileCitation['file_id'] ?? '',
                startIndex: $annotation['start_index'] ?? null,
                endIndex: $annotation['end_index'] ?? null,
                citedText: $annotation['text'] ?? null,
                metadata: [
                    'type' => 'file_citation',
                    'quote' => $fileCitation['quote'] ?? null,
                    'provider' => 'openai',
                ]
            );
        }

        if ($type === 'file_path') {
            $filePath = $annotation['file_path'] ?? [];
            return new Citation(
                id: $filePath['file_id'] ?? uniqid('openai_path_'),
                source: $filePath['file_id'] ?? '',
                startIndex: $annotation['start_index'] ?? null,
                endIndex: $annotation['end_index'] ?? null,
                citedText: $annotation['text'] ?? null,
                metadata: [
                    'type' => 'file_path',
                    'provider' => 'openai',
                ]
            );
        }

        return null;
    }
}
