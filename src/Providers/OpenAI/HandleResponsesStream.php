<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
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
            $json['tools'] = $this->generateToolsPayload();
        }

        // Attach tool choice
        if (!empty($this->toolChoice)) {
            $json['tool_choice'] = $this->toolChoice;
        }

        $stream = $this->client->post('responses', [
            'stream' => true,
            RequestOptions::JSON => $json
        ])->getBody();

        $toolCalls = [];

        while (! $stream->eof()) {
            if (!$event = $this->parseNextResponseDataLine($stream)) {
                continue;
            }

            switch ($event['type']) {
                case 'response.web_search_call.searching':
                    yield ['status' => 'web_search_call.searching'];
                    break;

                case 'response.web_search_call.completed':
                    yield ['status' => 'web_search_call.completed'];
                    break;

                case 'response.queued':
                    yield ['status' => 'queued'];
                    break;

                case 'response.function_call_arguments.done':
                    $toolCall = $this->composeResponseToolCalls($event, $toolCalls);
                    yield from $executeToolsCallback($toolCall);
                    return;

                case 'response.output_text.delta':
                    $content = $event['delta'] ?? '';
                    yield $content;
                    break;

                case 'response.completed':
                    $result = $event['response'];
                    yield $this->composeMessage($result);
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
     * Recreate the tool_calls format from streaming OpenAI API.
     *
     * @param  array<string, mixed>  $event
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    protected function composeResponseToolCalls(array $event, array $toolCalls): array
    {
        $index = $event['item_id'];

        if (!\array_key_exists($index, $toolCalls)) {
            if ($name = $event['item_id'] ?? null) {
                $toolCalls[$index]['function'] = [
                    'name' => $name,
                    'arguments' => $event['arguments'] ?? null
                ];
                $toolCalls[$index]['id'] = $event['item_id'];
                $toolCalls[$index]['type'] = 'function';
            }
        } else {
            $arguments = $event['arguments'] ?? null;
            if ($arguments !== null) {
                $toolCalls[$index]['function']['arguments'] .= $arguments;
            }
        }

        return $toolCalls;
    }

    protected function parseNextResponseDataLine(StreamInterface $stream): ?array
    {
        $line = $this->readResponseLine($stream);

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

    protected function readResponseLine(StreamInterface $stream): string
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

    protected function composeMessage(array $result): AssistantMessage
    {
        $output = \array_values(
            \array_filter(
                $result['output'],
                fn (array $message): bool => $message['type'] == 'message' && $message['role'] == MessageRole::ASSISTANT->value
            )
        );

        $content = $output[0]['content'][0];

        $message = new AssistantMessage(
            content: $content['text'],
        );

        $message->addMetadata('id', $output[0]['id']);

        if (isset($content['annotations'])) {
            $message->addMetadata('annotations', $content['annotations']);
        }

        /*foreach ($content['annotations'] ?? [] as $annotation) {
            if ($annotation['type'] === 'url_citation') {
                $message->addAnnotation(
                    new Annotation(
                        url: $annotation['url'],
                        title: $annotation['title'],
                        startIndex: $annotation['start_index'],
                        endIndex: $annotation['end_index'],
                    )
                );
            }
        }*/

        if (\array_key_exists('usage', $result)) {
            $message->setUsage(
                new Usage($result['usage']['input_tokens'], $result['usage']['output_tokens'])
            );
        }

        return $message;
    }
}
