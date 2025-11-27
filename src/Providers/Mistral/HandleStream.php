<?php

namespace NeuronAI\Providers\Mistral;

use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\StreamState;
use NeuronAI\Providers\SSEParser;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://docs.mistral.ai/api/endpoint/chat
     *
     * @throws ProviderException
     * @throws GuzzleException
     */
    public function stream(array|string $messages): \Generator
    {
        // Attach the system prompt
        if ($this->system !== null) {
            \array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'stream' => true,
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        // Attach tools
        if ($this->tools !== []) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $stream = $this->client->post('chat/completions', [
            'stream' => true,
            ...['json' => $json]
        ])->getBody();

        $this->streamState = new StreamState();

        while (! $stream->eof()) {
            if (($line = SSEParser::parseNextSSEEvent($stream)) === null) {
                continue;
            }

            // Capture usage information
            if (empty($line['choices']) && !empty($line['usage'])) {
                $this->streamState->addInputTokens($line['usage']['prompt_tokens'] ?? 0);
                $this->streamState->addOutputTokens($line['usage']['completion_tokens'] ?? 0);
                continue;
            }

            if (empty($line['choices'])) {
                continue;
            }

            $choice = $line['choices'][0];

            // Compile tool calls
            if ($this->isToolCallPart($line)) {
                $this->streamState->composeToolCalls($line);

                // Handle tool calls
                if ($choice['finish_reason'] === 'tool_calls') {
                    return $this->createToolCallMessage(
                        $this->streamState->getToolCalls(),
                        $this->streamState->getContentBlocks()
                    )->setUsage($this->streamState->getUsage());
                }

                continue;
            }

            // Process regular content
            $content = $choice['delta']['content'] ?? '';
            $this->streamState->updateContentBlock($choice['index'], $content);
            yield new TextChunk($this->streamState->messageId(), $content);
        }

        $message = new AssistantMessage($this->streamState->getContentBlocks());
        $message->setUsage($this->streamState->getUsage());

        return $message;
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
