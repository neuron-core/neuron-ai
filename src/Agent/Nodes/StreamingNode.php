<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\ChatHistoryHelper;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\StreamChunk;
use NeuronAI\Agent\ToolCallChunk;
use NeuronAI\Agent\ToolResultChunk;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;

class StreamingNode extends Node
{
    use ChatHistoryHelper;

    public function __construct(
        protected AIProviderInterface $provider,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function __invoke(AIInferenceEvent $event, AgentState $state): \Generator|ToolCallEvent
    {
        $chatHistory = $state->getChatHistory();
        $lastMessage = $chatHistory->getLastMessage();

        $this->emit(
            'inference-start',
            new InferenceStart($lastMessage)
        );

        if ($lastMessage instanceof ToolCallResultMessage) {
            yield new ToolResultChunk($lastMessage->getTools());
        }

        try {
            $content = '';
            $usage = new Usage(0, 0);

            // Use instructions and tools from the event
            $stream = $this->provider
                ->systemPrompt($event->instructions)
                ->setTools($event->tools)
                ->stream($chatHistory->getMessages());

            foreach ($stream as $chunk) {
                // Check if this is a ToolCallMessage
                if ($chunk instanceof ToolCallMessage) {
                    // Add the tool call message to the chat history
                    $chatHistory->addMessage($chunk);

                    $this->emit(
                        'inference-stop',
                        new InferenceStop($lastMessage, $chunk)
                    );

                    yield new ToolCallChunk($chunk->getTools());

                    // Go to the router node to handle the tool call
                    return new ToolCallEvent($chunk, $event);
                }

                // Parse usage information
                $decoded = \json_decode((string) $chunk, true);
                if (\is_array($decoded) && \array_key_exists('usage', $decoded)) {
                    $usage->inputTokens += $decoded['usage']['input_tokens'] ?? 0;
                    $usage->outputTokens += $decoded['usage']['output_tokens'] ?? 0;
                    continue;
                }

                // Accumulate content and yield text chunks
                $content .= $chunk;
                yield new StreamChunk(content: $chunk);
            }

            // Build final response message
            $response = new AssistantMessage($content);
            $response->setUsage($usage);

            // Avoid double-saving to chat history
            // This can happen when the workflow loops back after tool execution
            $lastMessage = $chatHistory->getLastMessage();
            if ($response->getRole() !== $lastMessage->getRole()) {
                $chatHistory->addMessage($response);
                $this->addToChatHistory($state, $response);
            }

            $this->emit(
                'inference-stop',
                new InferenceStop($lastMessage, $response)
            );

            return new StopEvent();

        } catch (\Throwable $exception) {
            $this->emit('error', new AgentError($exception));
            throw $exception;
        }
    }
}
