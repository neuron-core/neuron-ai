<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAI\StreamState;
use NeuronAI\Providers\HandleEarlyStreamEnd;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\SSEParser;

use function rtrim;
use function array_unshift;
use function array_merge;

trait HandleStream
{
    use HandleEarlyStreamEnd;

    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://docs.mistral.ai/api/endpoint/chat
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        // Attach the system prompt
        if ($this->system !== null) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $body = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...array_merge($this->parameters, ['stream_options' => ['include_usage' => true]]),
        ];

        // Attach tools
        if ($this->tools !== []) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: rtrim($this->baseUri, '/') . '/chat/completions',
                body: $body,
                headers: $this->httpHeaders,
            )
        );

        $this->streamState = new StreamState();
        $lastFinishReason = null;

        while (! $stream->eof()) {
            if (($line = SSEParser::parseNextSSEEvent($stream)) === null) {
                continue;
            }

            // Capture usage information
            if (!empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['prompt_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['completion_tokens'] ?? 0);
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];
            $lastFinishReason = $choice['finish_reason'] ?? $lastFinishReason;

            // Compile tool calls
            if ($this->isToolCallPart($line)) {
                $this->streamState->composeToolCalls($line);

                foreach ($choice['delta']['tool_calls'] as $call) {
                    $arguments = $call['function']['arguments'] ?? '';
                    $toolCall = $this->streamState->getToolCall($call['index']);
                    if ($arguments !== '' && $toolCall !== null) {
                        yield new ToolArgumentChunk(
                            $this->streamState->messageId(),
                            $toolCall['function']['name'],
                            $arguments,
                            $toolCall['id'] ?? null,
                        );
                    }
                }

                continue;
            }

            // The role and finish deltas carry an empty string: a text block opened for them would precede the thinking
            $content = $choice['delta']['content'] ?? '';
            $blocks = $content === '' ? [] : $this->extractContent($content);

            foreach ($blocks as $block) {
                // A key of its own keeps the thinking apart from the answer's text block
                $this->streamState->updateContentBlock($block instanceof ReasoningContent ? -1 : $choice['index'], $block);

                $chunk = match ($block::class) {
                    TextContent::class => new TextChunk($this->streamState->messageId(), $block->getContent()),
                    ReasoningContent::class => $block->getContent() !== ''
                        ? new ReasoningChunk($this->streamState->messageId(), $block->getContent())
                        : null,
                    default => null,
                };

                if ($chunk !== null) {
                    yield $chunk;
                }
            }
        }

        // The last choice carries the finish reason: without it the stream ended early
        if ($lastFinishReason === null) {
            return $this->earlyEndResponse($stream, $this->streamState->getContentBlocks(), $this->streamState->messageId(), $this->streamState->getUsage());
        }

        // Built once the stream ends: the tool_calls finish reason may arrive in a later chunk than the calls
        $blocks = $this->streamState->getContentBlocks();

        $message = $this->streamState->hasToolCalls()
            ? $this->createToolCallMessage($this->streamState->getToolCalls(), $blocks)
            : new AssistantMessage($blocks);
        $message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());

        $message->setStopReason($lastFinishReason);

        return new ProviderResponse(message: $message);
    }

    protected function isToolCallPart(array $line): bool
    {
        $calls = $line['choices'][0]['delta']['tool_calls'] ?? [];

        foreach ($calls as $call) {
            if (isset($call['function'])) {
                return true;
            }
        }

        return false;
    }
}
