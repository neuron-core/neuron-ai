<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\SSEParser;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://platform.openai.com/docs/api-reference/chat-streaming
     *
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages): \Generator
    {
        // Attach the system prompt
        if (isset($this->system)) {
            \array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            'stream_options' => ['include_usage' => true],
            ...$this->parameters,
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->client->post('chat/completions', [
            'stream' => true,
            RequestOptions::JSON => $json
        ])->getBody();

        $this->streamState = new StreamState();

        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            $this->streamState->messageId($line['id']);

            // Capture usage information
            if (!empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['prompt_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['completion_tokens'] ?? 0);
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];

            // Compile tool calls
            if (isset($choice['delta']['tool_calls'])) {
                $this->streamState->composeToolCalls($line);

                if ($this->finishForToolCall($choice)) {
                    goto toolcall;
                }

                continue;
            }

            // Handle tool calls
            if ($this->finishForToolCall($choice)) {
                toolcall:
                return $this->createToolCallMessage(
                    $this->streamState->getToolCalls(),
                    $this->streamState->getContentBlocks()
                )->setUsage($this->streamState->getUsage());
            }

            // Process regular content
            if ($content = $choice['delta']['content'] ?? null) {
                $this->streamState->updateContentBlock($choice['index'], $content);
                yield new TextChunk($this->streamState->messageId(), $content);
            }
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());

        return $message;
    }

    protected function finishForToolCall(array $choice): bool
    {
        return isset($choice['finish_reason']) && $choice['finish_reason'] === 'tool_calls';
    }
}
