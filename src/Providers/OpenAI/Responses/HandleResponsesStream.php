<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\StreamInterface;

/**
 * Inspired by Andrew Monty - https://github.com/AndrewMonty
 */
trait HandleResponsesStream
{
    /**
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages, callable $executeToolsCallback): \Generator
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

        while (! $stream->eof()) {
            if (!$event = $this->parseNextDataLine($stream)) {
                continue;
            }

            switch ($event['type']) {
                // Comment for now to maintain backward compatibility. They can be added later.
                /*case 'response.web_search_call.searching':
                    yield ['status' => 'web_search_call.searching'];
                    break;

                case 'response.web_search_call.completed':
                    yield ['status' => 'web_search_call.completed'];
                    break;

                case 'response.queued':
                    yield ['status' => 'queued'];
                    break;*/

                // Initialize the tool call
                case 'response.output_item.added':
                    if ($event['item']['type'] == 'function_call') {
                        $toolCalls[$event['item']['id']] = [
                            'name' => $event['item']['name'],
                            'arguments' => $event['item']['arguments'] ?? null,
                            'call_id' => $event['item']['call_id'],
                        ];
                    }
                    break;

                    // Update the tool call arguments
                case 'response.function_call_arguments.done':
                    $toolCalls[$event['item_id']]['arguments'] = $event['arguments'];
                    break;

                    // Stream delta text
                case 'response.output_text.delta':
                    yield $event['delta'] ?? '';
                    break;

                    // Return the final message
                case 'response.completed':
                    if ($toolCalls !== []) {
                        yield from $executeToolsCallback(
                            $this->createToolCallMessage($toolCalls, $event['response']['usage'] ?? null)
                        );
                    } else {
                        return $this->createAssistantMessage($event['response']);
                    }
                    break;

                case 'response.failed':
                    throw new ProviderException('OpenAI streaming error: ' . $event['error']['message']);

                default:
                    // Ignore other events like response.start, metadata, etc.
                    break;
            }
        }
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
