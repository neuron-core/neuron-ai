<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\ResponseInterface;

use function array_unshift;
use function json_decode;
use function array_filter;
use function array_reduce;
use function is_string;

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
            ->then(function (ResponseInterface $result): AssistantMessage|ToolCallMessage {
                $result = json_decode($result->getBody()->getContents(), true);
                return $this->processChatResult($result);
            });
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        $choice = $result['choices'][0];

        if ($choice['finish_reason'] === 'tool_calls') {
            $response = $this->createToolCallMessage(
                $choice['message']['tool_calls'],
                new TextContent($choice['message']['content'])
            );
        } elseif (is_string($choice['message']['content'])) {
            $response = new AssistantMessage($choice['message']['content']);
        } else {
            $blocks = [];
            foreach ($choice['content'] as $content) {
                $blocks[] = match ($content['type']) {
                    'text' => new TextContent($content['text'] ?? ''),
                    'thinking' => new ReasoningContent(array_reduce(array_filter($content['thinking'], fn (array $item): bool => $item['type'] === 'text'), fn (string $carry, array $item): string => $carry . $item['text'], '')),
                    'image_url' => new ImageContent(
                        $content['image_url']['url'] ?? '',
                        SourceType::BASE64
                    ),
                    'document_url' => new FileContent(
                        content: $content['document_url'] ?? '',
                        sourceType: SourceType::BASE64,
                        filename: $content['document_name'] ?? null
                    ),
                    'input_audio' => new AudioContent($content['input_audio'], SourceType::BASE64),
                    default => null
                };
            }
            $response = new AssistantMessage(array_filter($blocks));
        }

        if (isset($result['usage'])) {
            $response->setUsage(
                new Usage($result['usage']['prompt_tokens'], $result['usage']['completion_tokens'])
            );
        }

        return $response;
    }
}
