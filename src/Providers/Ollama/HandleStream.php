<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use Generator;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\ProviderResponse;

use function json_encode;
use function is_string;
use function rtrim;
use function array_unshift;
use function json_decode;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $body = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (!empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: rtrim($this->baseUri, '/') . '/chat',
                body: $body,
                headers: $this->httpHeaders,
            )
        );

        $this->streamState = new StreamState();
        $toolCalls = [];

        // Every line is read whole: tool calls may span lines, and the usage
        // arrives on the final done line, after them
        while (! $stream->eof()) {
            if (!$line = $this->parseNextJson($stream)) {
                continue;
            }

            $thinking = $line['message']['thinking'] ?? '';
            if ($thinking !== '') {
                $this->streamState->reasoning .= $thinking;
                yield new ReasoningChunk($this->streamState->messageId(), $thinking);
            }

            // A token can be "0": compare, never test truthiness
            $content = $line['message']['content'] ?? '';
            if ($content !== '') {
                $this->streamState->text .= $content;
                yield new TextChunk($this->streamState->messageId(), $content);
            }

            foreach ($line['message']['tool_calls'] ?? [] as $toolCall) {
                $toolCalls[] = $toolCall;
            }

            if (($line['done'] ?? false) === true) {
                $this->streamState->addInputTokens($line['prompt_eval_count'] ?? 0);
                $this->streamState->addOutputTokens($line['eval_count'] ?? 0);
            }
        }

        if ($toolCalls !== []) {
            $message = $this->createToolCallMessage($toolCalls, $this->streamState->getContentBlocks())
                ->setId($this->streamState->messageId())
                ->setUsage($this->streamState->getUsage());

            return new ProviderResponse(message: $message);
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setId($this->streamState->messageId())->setUsage($this->streamState->getUsage());

        return new ProviderResponse(message: $message);
    }

    protected function parseNextJson(StreamInterface $stream): ?array
    {
        $line = $stream->readLine();

        if ($line === '' || $line === '0') {
            return null;
        }

        $json = json_decode($line, true);

        // Ollama reports a failure mid-generation as an error line
        if (isset($json['error'])) {
            throw new ProviderException('Ollama stream error: ' . (is_string($json['error']) ? $json['error'] : json_encode($json['error'])));
        }

        if (! isset($json['message']) || $json['message']['role'] !== 'assistant') {
            return null;
        }

        return $json;
    }
}
