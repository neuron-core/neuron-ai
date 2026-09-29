<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\ProviderResponse;

use function rtrim;
use function array_unshift;

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
            'stream' => false,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (! empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: rtrim($this->baseUri, '/') . '/chat',
                body: $body,
                headers: $this->httpHeaders,
            )
        );

        if (!$response->isSuccessful()) {
            throw new ProviderException("Ollama chat error: {$response->body}");
        }

        return new ProviderResponse(
            message: $this->processResponse($response->json()),
            body: $response->body,
            headers: $response->headers,
        );
    }

    /**
     * @throws ProviderException
     */
    protected function processResponse(array $response): AssistantMessage
    {
        $blocks = $this->contentBlocks($response['message']);

        if (isset($response['message']['tool_calls'])) {
            $message = $this->createToolCallMessage($response['message']['tool_calls'], $blocks);
        } else {
            $message = new AssistantMessage($blocks);
        }

        if (isset($response['prompt_eval_count'], $response['eval_count'])) {
            $message->setUsage(
                new Usage($response['prompt_eval_count'], $response['eval_count'])
            );
        }

        if (isset($response['done_reason'])) {
            $message->setStopReason($response['done_reason']);
        }

        return $message;
    }

    /**
     * The same blocks, in the same order, as the stream builds.
     *
     * @param array<string, mixed> $message
     * @return ContentBlockInterface[]
     */
    protected function contentBlocks(array $message): array
    {
        $blocks = [];

        if (($message['content'] ?? '') !== '') {
            $blocks[] = new TextContent($message['content']);
        }

        if (($message['thinking'] ?? '') !== '') {
            $blocks[] = new ReasoningContent($message['thinking']);
        }

        return $blocks;
    }
}
