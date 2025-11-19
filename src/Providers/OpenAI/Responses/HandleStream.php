<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;

/**
 * Originally inspired by Andrew Monty - https://github.com/AndrewMonty
 */
trait HandleStream
{
    /**
     * Stream response from the LLM.
     *
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages): \Generator
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

        $toolCalls = [];
        $blocks = [];

        while (! $stream->eof()) {
            if (!$event = $this->parseNextDataLine($stream)) {
                continue;
            }

            switch ($event['type']) {
                // Initialize the tool call
                case 'response.output_item.added':
                    if ($event['item']['type'] === 'function_call') {
                        $toolCalls[$event['item']['id']] = [
                            'name' => $event['item']['name'],
                            'arguments' => $event['item']['arguments'] ?? null,
                            'call_id' => $event['item']['call_id'],
                        ];
                    }
                    if ($event['item']['type'] === 'message') {
                        $blocks[$event['item']['id']] = new TextContent($event['item']['content'][0]['text'] ?? '');
                    }
                    break;

                    // Collect tool call arguments
                case 'response.function_call_arguments.done':
                    $toolCalls[$event['item_id']]['arguments'] = $event['arguments'];
                    break;

                    // Stream delta text
                case 'response.output_text.delta':
                    $content = $event['delta'] ?? '';
                    $blocks[$event['item_id']]->text .= $content;
                    yield new TextChunk($content);
                    break;

                case 'response.reasoning_summary_part.added':
                    $content = $event['part']['text'] ?? '';
                    $blocks[$event['item_id']] = new ReasoningContent($content);
                    yield new ReasoningChunk($content);
                    break;

                case 'response.reasoning_summary_text.delta':
                    $content = $event['delta'] ?? '';
                    $blocks[$event['item_id']]->text .= $content;
                    yield new ReasoningChunk($content);
                    break;

                    // Return the final message
                case 'response.completed':
                    if ($toolCalls !== []) {
                        return $this->createToolCallMessage($toolCalls, $blocks, $event['response']['usage'] ?? null);
                    }
                    return $this->createAssistantMessage($event['response']);

                case 'response.failed':
                    throw new ProviderException('OpenAI streaming error: ' . $event['error']['message']);

                default:
                    // Ignore other events
                    break;
            }
        }

        // If we reach here without a response.completed event, return an assistant message
        return new AssistantMessage($blocks);
    }

    /**
     * @throws ProviderException
     */
    protected function parseNextDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readLine($stream);

        if (! \str_starts_with((string) $line, 'data:')) {
            return null;
        }

        $line = \trim(\substr((string) $line, \strlen('data: ')));

        if (\str_contains($line, 'DONE')) {
            return null;
        }

        try {
            $event = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
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
