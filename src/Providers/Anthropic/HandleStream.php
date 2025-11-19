<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\SSEParser;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     *
     * Yields intermediate chunks during streaming and returns the final complete Message.
     *
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages): \Generator
    {
        $json = [
            'stream' => true,
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'system' => $this->system ?? null,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        // https://docs.anthropic.com/claude/reference/messages_post
        $stream = $this->client->post('messages', [
            'stream' => true,
            RequestOptions::JSON => $json
        ])->getBody();

        $this->streamState = new StreamState();

        // https://docs.anthropic.com/en/api/messages-streaming
        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            $eventType = $line['type'] ?? null;

            if ($eventType === 'message_start') {
                $this->handleMessageStart($line['message']);
                continue;
            }

            if ($eventType === 'message_delta') {
                $this->handleMessageDelta($line);
                continue;
            }

            if ($eventType === 'content_block_start') {
                $this->handleBlockStart($line);
                continue;
            }

            if ($eventType === 'content_block_delta') {
                yield from $this->handleBlockDelta($line);
            }
        }

        // Build the final message
        if ($this->streamState->hasToolCalls()) {
            return $this->createToolCallMessage(
                $this->streamState->getToolCalls(),
                $this->streamState->blocks
            )->setUsage($this->streamState->getUsage());
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        return $message->setUsage($this->streamState->getUsage());
    }

    protected function handleMessageStart(array $message): void
    {
        $this->streamState->messageId($message['id']);
        $this->streamState->addInputTokens($message['usage']['input_tokens'] ?? 0);
        $this->streamState->addOutputTokens($message['usage']['output_tokens'] ?? 0);
    }

    protected function handleMessageDelta(array $event): void
    {
        $this->streamState->addOutputTokens($event['usage']['output_tokens'] ?? 0);
    }

    protected function handleBlockStart(array $event): void
    {
        $index = $event['index'];
        $type = $event['content_block']['type'] ?? null;

        if ($type === 'text') {
            $this->streamState->blocks[$index] = new TextContent('');
        } elseif ($type === 'thinking') {
            $this->streamState->blocks[$index] = new ReasoningContent('');
        } elseif ($type === 'tool_use') {
            $this->streamState->composeToolCalls($event);
        }
    }

    protected function handleBlockDelta(array $event): \Generator
    {
        $index = $event['index'];
        $delta = $event['delta'];

        if ($delta['type'] === 'text_delta') {
            $text = $delta['text'];
            $this->streamState->blocks[$index]->text .= $text;
            yield new TextChunk($this->streamState->messageId(), $text);
            return;
        }

        if ($delta['type'] === 'thinking_delta') {
            $thinking = $delta['thinking'];
            $this->streamState->blocks[$index]->text .= $thinking;
            yield new ReasoningChunk($this->streamState->messageId(), $thinking);
            return;
        }

        if ($delta['type'] === 'signature_delta') {
            $this->streamState->blocks[$index]->id = $delta['signature'];
            return;
        }

        if ($delta['type'] === 'input_json_delta') {
            $this->streamState->composeToolCalls($event);
        }
    }
}
