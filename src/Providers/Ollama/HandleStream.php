<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\SSEParser;
use Psr\Http\Message\StreamInterface;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     *
     * @throws GuzzleException
     * @throws ProviderException
     */
    public function stream(array|string $messages): \Generator
    {
        // Include the system prompt
        if (isset($this->system)) {
            \array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->client->post('chat', [
            'stream' => true,
            ...['json' => $json]
        ])->getBody();

        $this->streamState = new StreamState();

        while (! $stream->eof()) {
            if (!$line = $this->parseNextJson($stream)) {
                continue;
            }

            // Process tool calls
            if (isset($line['message']['tool_calls'])) {
                return $this->createToolCallMessage(
                    $line['message']['tool_calls'],
                    $this->streamState->getContentBlocks()
                )->setUsage($this->streamState->getUsage());
            }

            if ($thinking = $line['message']['thinking'] ?? null) {
                $this->streamState->reasoning .= $thinking;
                yield new ReasoningChunk($this->streamState->messageId(), $thinking);
                continue;
            }

            // Process regular content
            if ($content = $line['message']['content'] ?? null) {
                $this->streamState->text .= $content;
                yield new TextChunk($this->streamState->messageId(), $content);
                continue;
            }

            // The last chunk will contain the usage information
            if ($line['done'] === true) {
                $this->streamState->addInputTokens($line['prompt_eval_count'] ?? 0);
                $this->streamState->addOutputTokens($line['eval_count'] ?? 0);
            }
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());

        return $message;
    }

    protected function parseNextJson(StreamInterface $stream): ?array
    {
        $line = SSEParser::readLine($stream);

        if ($line === '' || $line === '0') {
            return null;
        }

        $json = \json_decode($line, true);

        if ($json['done']) {
            return null;
        }

        if (! isset($json['message']) || $json['message']['role'] !== 'assistant') {
            return null;
        }

        return $json;
    }
}
