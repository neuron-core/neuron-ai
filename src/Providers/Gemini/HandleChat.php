<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;

use function array_filter;
use function array_key_exists;
use function json_encode;
use function trim;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
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

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: trim($this->baseUri, '/')."/{$this->model}:generateContent",
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
        if (array_key_exists('error', $result)) {
            throw new ProviderException("Gemini API Error: " . ($result['error']['message'] ?? json_encode($result['error'])));
        }

        if (!array_key_exists('candidates', $result) || empty($result['candidates'])) {
            throw new ProviderException("Gemini API returned no candidates. Response: " . json_encode($result));
        }

        $candidate = $result['candidates'][0];
        $finishReason = $candidate['finishReason'] ?? 'UNKNOWN';

        if ($finishReason !== 'STOP' && !isset($candidate['content'])) {
            throw new ProviderException("Gemini API finished with reason: {$finishReason}. Full response: " . json_encode($result));
        }

        $content = $candidate['content'];

        if (!isset($content['parts']) && $finishReason === 'MAX_TOKENS') {
            return new AssistantMessage('');
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
                    $part['inlineData']['mimeType']
                );
            }

            if (isset($part['functionCall'])) {
                $toolCalls = array_filter($content['parts'], fn (array $item): bool => isset($item['functionCall']));
                $message = $this->createToolCallMessage($blocks, $toolCalls);
                break;
            }
        }

        if (!isset($message)) {
            $message = new AssistantMessage($blocks);
        }

        if (array_key_exists('groundingMetadata', $result['candidates'][0])) {
            // Extract citations from groundingMetadata
            $citations = $this->extractCitations($result['candidates'][0]['groundingMetadata']);
            if (!empty($citations)) {
                $message->addMetadata('citations', $citations);
            }
        }

        // Attach the usage for the current interaction
        if (array_key_exists('usageMetadata', $result)) {
            $message->setUsage(
                new Usage(
                    $result['usageMetadata']['promptTokenCount'],
                    $result['usageMetadata']['candidatesTokenCount'] ?? 0
                )
            );
        }

        $message->setStopReason($finishReason);

        return $message;
    }
}
