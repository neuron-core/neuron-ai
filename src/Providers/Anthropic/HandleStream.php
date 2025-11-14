<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Events\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Events\TextChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;

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

        $contentBlocks = [];
        $currentBlockIndex = null;
        $currentBlockType = null;

        // https://docs.anthropic.com/en/api/messages-streaming
        while (! $stream->eof()) {
            if (!$line = $this->parseNextDataLine($stream)) {
                continue;
            }

            if ($line['type'] === 'message_start') {
                $this->streamState->addInputTokens($line['message']['usage']['input_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['message']['usage']['output_tokens'] ?? 0);
                continue;
            }

            if ($line['type'] === 'message_delta') {
                $this->streamState->addOutputTokens($line['usage']['output_tokens'] ?? 0);
                continue;
            }

            // Track content block start
            if ($line['type'] === 'content_block_start') {
                $currentBlockIndex = $line['index'];
                $currentBlockType = $line['content_block']['type'] ?? null;

                // Initialize content block
                if ($currentBlockType === 'text') {
                    $contentBlocks[$currentBlockIndex] = new TextContent('');
                } elseif ($currentBlockType === 'thinking') {
                    $contentBlocks[$currentBlockIndex] = new ReasoningContent('');
                }

                if ($currentBlockType === 'tool_use') {
                    $this->streamState->composeToolCalls($line);
                    continue;
                }

                continue;
            }

            // Handle content block deltas
            if ($line['type'] === 'content_block_delta') {
                $delta = $line['delta'];

                if ($delta['type'] === 'text_delta') {
                    $text = $delta['text'];
                    $contentBlocks[$currentBlockIndex]->text .= $text;
                    yield new TextChunk($text);
                    continue;
                }

                if ($delta['type'] === 'thinking_delta') {
                    $thinking = $delta['thinking'];
                    $contentBlocks[$currentBlockIndex]->text .= $thinking;
                    yield new ReasoningChunk($thinking);
                    continue;
                }

                if ($delta['type'] === 'signature_delta') {
                    $contentBlocks[$currentBlockIndex]->id = $delta['signature'];
                    continue;
                }

                if ($delta['type'] === 'input_json_delta') {
                    $this->streamState->composeToolCalls($line);
                    continue;
                }
            }

            // Handle content block stop
            if ($line['type'] === 'content_block_stop') {
                $currentBlockIndex = null;
                $currentBlockType = null;
            }
        }

        // Build final message
        if ($this->streamState->hasToolCalls()) {
            return $this->createToolCallMessage(
                $this->streamState->getToolCalls(),
                $contentBlocks
            )->setUsage($this->streamState->getUsage());
        }

        $message = new AssistantMessage($contentBlocks);
        return $message->setUsage($this->streamState->getUsage());
    }

    protected function parseMessageStart(array $event): \Generator
    {

    }

    /**
     * Recreate the tool_call format of anthropic API from streaming.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function composeToolCalls(array $line, array $toolCalls): array
    {
        if (!\array_key_exists($line['index'], $toolCalls)) {
            $toolCalls[$line['index']] = [
                'type' => 'tool_use',
                'id' => $line['content_block']['id'],
                'name' => $line['content_block']['name'],
                'input' => '',
            ];
        } elseif ($input = $line['delta']['partial_json'] ?? null) {
            $toolCalls[$line['index']]['input'] .= $input;
        }

        return $toolCalls;
    }

    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! \str_starts_with((string) $line, 'data:')) {
            return null;
        }

        $line = \trim(\substr((string) $line, \strlen('data: ')));

        try {
            return \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new ProviderException('Anthropic streaming error - '.$exception->getMessage());
        }
    }

    protected function readLine(StreamInterface $stream): string
    {
        $buffer = '';

        while (! $stream->eof()) {
            $byte = $stream->read(1);

            if ($byte === '') {
                return $buffer;
            }

            $buffer .= $byte;

            if ($byte === "\n") {
                break;
            }
        }

        return $buffer;
    }
}
