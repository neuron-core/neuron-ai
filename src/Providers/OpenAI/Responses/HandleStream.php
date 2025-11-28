<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;
use Generator;
use Throwable;

use function json_decode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Originally inspired by Andrew Monty - https://github.com/AndrewMonty
 */
trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     *
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages): Generator
    {
        $json = [
            'stream' => true,
            'model' => $this->model,
            'input' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach the system prompt
        if (isset($this->system)) {
            $json['instructions'] = $this->system;
        }

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->client->post('responses', [
            'stream' => true,
            RequestOptions::JSON => $json
        ])->getBody();

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

                    // Collect tool call arguments
                case 'response.function_call_arguments.done':
                    $this->streamState->composeToolCalls($event);
                    break;

                    // Stream delta text
                case 'response.output_text.delta':
                    $content = $event['delta'] ?? '';
                    $this->streamState->updateContentBlock($event['item_id'], $content);
                    yield new TextChunk($event['item_id'], $content);
                    break;

                    /*
                     * Reasoning
                     */
                case 'response.reasoning_summary_part.added':
                    $content = $event['part']['text'] ?? '';
                    $this->streamState->addContentBlock($event['item_id'], new ReasoningContent($content));
                    yield new ReasoningChunk($event['item_id'], $content);
                    break;
                case 'response.reasoning_summary_text.delta':
                    $content = $event['delta'] ?? '';
                    $this->streamState->updateContentBlock($event['item_id'], $content);
                    yield new ReasoningChunk($event['item_id'], $content);
                    break;

                    /*
                     * Image
                     */
                case 'response.image_generation_call.generating':
                    $this->streamState->addContentBlock($event['item_id'], new ImageContent('', SourceType::BASE64));
                    break;
                case 'response.image_generation_call.partial_image':
                    $this->streamState->updateContentBlock($event['item_id'], $event['partial_image_b64']);
                    break;

                    /*
                     * Return the final message
                     */
                case 'response.completed':
                    $usage = $event['response']['usage'] ?? null;
                    $this->streamState->addInputTokens($usage['input_tokens'] ?? 0);
                    $this->streamState->addOutputTokens($usage['output_tokens'] ?? 0);

                    if ($this->streamState->hasToolCalls()) {
                        return $this->createToolCallMessage(
                            $this->streamState->getToolCalls(),
                            $this->streamState->getContentBlocks(),
                        )->setUsage($this->streamState->getUsage());
                    }
                    return $this->createAssistantMessage($event['response'])->setUsage($usage);

                case 'response.failed':
                    throw new ProviderException('OpenAI streaming error: ' . $event['response']['error']['message']);

                default:
                    // Ignore other events
                    break;
            }
        }

        // If we reach here without a response.completed event, return an assistant message
        return new AssistantMessage($this->streamState->getContentBlocks());
    }

    /**
     * @throws ProviderException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! str_starts_with((string) $line, 'data:')) {
            return null;
        }

        $line = trim(substr((string) $line, strlen('data: ')));

        if (str_contains($line, 'DONE')) {
            return null;
        }

        try {
            $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new ProviderException('OpenAI streaming JSON decode error: ' . $exception->getMessage());
        }

        if (!isset($event['type'])) {
            return null;
        }

        return $event;
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
