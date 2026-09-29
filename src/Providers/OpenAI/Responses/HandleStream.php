<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use Generator;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\HandleEarlyStreamEnd;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\SSEParser;

use function json_encode;
use function rtrim;

/**
 * Originally inspired by Andrew Monty - https://github.com/AndrewMonty
 */
trait HandleStream
{
    use HandleEarlyStreamEnd;

    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        $body = $this->requestBody($messages, true);

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: rtrim($this->baseUri, '/') . '/responses',
                body: $body,
                headers: $this->httpHeaders,
            )
        );

        $this->streamState = new StreamState();

        while (! $stream->eof()) {
            if (!$event = $this->parseNextDataLine($stream)) {
                continue;
            }

            switch ($event['type']) {
                // Initialize tool or text items
                case 'response.output_item.added':
                    if ($event['item']['type'] === 'function_call') {
                        $this->streamState->composeToolCalls($event);
                    }
                    if ($event['item']['type'] === 'message') {
                        $this->streamState->addContentBlock($event['item']['id'], new TextContent($event['item']['content'][0]['text'] ?? ''));
                    }
                    break;

                    // Stream tool call argument fragments. The final arguments are
                    // consolidated by the "done" event below, so this only yields.
                case 'response.function_call_arguments.delta':
                    $delta = $event['delta'] ?? '';
                    $toolCall = $this->streamState->getToolCall($event['item_id']);
                    if ($delta !== '' && $toolCall !== null) {
                        yield new ToolArgumentChunk(
                            $this->streamState->messageId(),
                            $toolCall['name'],
                            $delta,
                            $toolCall['call_id'] ?? null,
                        );
                    }
                    break;

                    // Collect tool call arguments
                case 'response.function_call_arguments.done':
                    $this->streamState->composeToolCalls($event);
                    break;

                    // Stream delta text
                case 'response.output_text.delta':
                    $content = $event['delta'] ?? '';
                    $this->streamState->updateContentBlock($event['item_id'], $content);
                    yield new TextChunk($this->streamState->messageId(), $content);
                    break;

                    /*
                     * Reasoning
                     */
                case 'response.reasoning_summary_part.added':
                    $content = $event['part']['text'] ?? '';
                    $this->streamState->addContentBlock($event['item_id'], new ReasoningContent($content));
                    if ($content !== '') {
                        yield new ReasoningChunk($this->streamState->messageId(), $content);
                    }
                    break;
                case 'response.reasoning_summary_text.delta':
                    $content = $event['delta'] ?? '';
                    $this->streamState->updateContentBlock($event['item_id'], $content);
                    if ($content !== '') {
                        yield new ReasoningChunk($this->streamState->messageId(), $content);
                    }
                    break;

                    /*
                     * Image
                     */
                    // Each partial image is a complete preview: it replaces the previous one
                case 'response.image_generation_call.partial_image':
                    $this->streamState->addContentBlock(
                        $event['item_id'],
                        $this->createImageContent($event['partial_image_b64'], $event['output_format'] ?? null)
                    );
                    yield new ImageChunk($this->streamState->messageId(), $event['partial_image_b64']);
                    break;
                case 'response.output_item.done':
                    if ($event['item']['type'] === 'image_generation_call' && isset($event['item']['result'])) {
                        $this->streamState->addContentBlock(
                            $event['item']['id'],
                            $this->createImageContent($event['item']['result'], $event['item']['output_format'] ?? null)
                        );
                    }
                    break;

                    /*
                     * Return the final message
                     */
                    // A truncated answer (max_output_tokens, content filter) ends with
                    // response.incomplete, which carries the same usage and output
                case 'response.completed':
                case 'response.incomplete':
                    $usage = $event['response']['usage'] ?? null;
                    $this->streamState->addInputTokens($usage['input_tokens'] ?? 0);
                    $this->streamState->addOutputTokens($usage['output_tokens'] ?? 0);
                    $this->streamState->addCachedInputTokens($usage['input_tokens_details']['cached_tokens'] ?? 0);
                    $this->streamState->addReasoningTokens($usage['output_tokens_details']['reasoning_tokens'] ?? 0);

                    $message = $this->streamState->hasToolCalls()
                        ? $this->createToolCallMessage($this->streamState->getToolCalls(), $this->streamState->getContentBlocks())
                        : $this->createAssistantMessage($event['response']);
                    $message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());

                    if (isset($event['response']['status'])) {
                        $message->setStopReason($event['response']['status']);
                    }

                    return new ProviderResponse(message: $message);

                case 'response.failed':
                    throw new ProviderException('OpenAI streaming error: ' . $event['response']['error']['message']);

                case 'error':
                    throw new ProviderException('OpenAI streaming error: ' . ($event['message'] ?? json_encode($event)));

                default:
                    // Ignore other events
                    break;
            }
        }

        // Neither response.completed nor response.incomplete arrived: the stream ended early
        return $this->earlyEndResponse($stream, $this->streamState->getContentBlocks(), $this->streamState->messageId(), $this->streamState->getUsage());
    }

    /**
     * @throws ProviderException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $event = SSEParser::parseNextSSEEvent($stream);

        return isset($event['type']) ? $event : null;
    }
}
