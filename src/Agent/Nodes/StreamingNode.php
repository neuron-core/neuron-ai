<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIResponseEvent;
use NeuronAI\Agent\StreamChunk;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolCallResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Observable;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StartEvent;

/**
 * Node responsible for streaming AI provider responses.
 *
 * This node yields StreamChunk objects during execution and returns AIResponseEvent at the end.
 * When a ToolCallMessage is detected, it returns AIResponseEvent with the ToolCallMessage,
 * allowing RouterNode to route it to ToolNode for execution, which then loops back via StartEvent.
 */
class StreamingNode extends Node
{
    use Observable;

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        protected AIProviderInterface $provider,
        protected string $instructions,
        protected array $tools = []
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function __invoke(StartEvent $event, AgentState $state): \Generator
    {
        $chatHistory = $state->getChatHistory();

        $this->notify(
            'inference-start',
            new InferenceStart($chatHistory->getLastMessage())
        );

        if ($chatHistory->getLastMessage() instanceof ToolCallResultMessage) {
            yield $chatHistory->getLastMessage();
        }

        try {
            $content = '';
            $usage = new Usage(0, 0);

            $stream = $this->provider
                ->systemPrompt($this->instructions)
                ->setTools($this->tools)
                ->stream($chatHistory->getMessages());

            foreach ($stream as $chunk) {
                // Check if this is a ToolCallMessage
                if ($chunk instanceof ToolCallMessage) {
                    // Add the tool call message to the chat history
                    $chatHistory->addMessage($chunk);

                    $this->notify(
                        'inference-stop',
                        new InferenceStop($chatHistory->getLastMessage(), $chunk)
                    );

                    yield $chunk;

                    // Go to the router node to handle the tool call
                    return new AIResponseEvent($chunk);
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
                yield new StreamChunk($chunk);
            }

            // Build final response message
            $response = new AssistantMessage($content);
            $response->setUsage($usage);

            // Avoid double-saving to chat history
            // This can happen when the workflow loops back after tool execution
            $lastMessage = $chatHistory->getLastMessage();
            if ($response->getRole() !== $lastMessage->getRole()) {
                $chatHistory->addMessage($response);
            }

            $this->notify(
                'inference-stop',
                new InferenceStop($chatHistory->getLastMessage(), $response)
            );

            return new AIResponseEvent($response);

        } catch (\Throwable $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }
    }
}
