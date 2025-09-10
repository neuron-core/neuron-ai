<?php

declare(strict_types=1);

namespace NeuronAI;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\AgentError;

trait HandleStream
{
    public function stream(Message|array $messages): \Generator
    {
        try {
            $this->notify('stream-start');

            $this->addToChatHistory($messages);

            $tools = $this->bootstrapTools();

            $stream = $this->resolveProvider()
                ->systemPrompt($this->resolveInstructions())
                ->setTools($tools)
                ->stream(
                    $this->resolveChatHistory()->getMessages(),
                    function (ToolCallMessage $toolCallMessage) {
                        yield $toolCallMessage;
                        $toolCallResult = $this->executeTools($toolCallMessage);
                        yield from self::stream([$toolCallMessage, $toolCallResult]);
                    }
                );

            $content = '';
            $usage = new Usage(0, 0);
            foreach ($stream as $chunk) {
                if ($chunk instanceof ToolCallMessage) {
                    yield $chunk;
                    continue;
                }

                // Catch usage when streaming
                $decoded = \json_decode((string) $chunk, true);
                if (\is_array($decoded) && \array_key_exists('usage', $decoded)) {
                    $usage->inputTokens += $decoded['usage']['input_tokens'] ?? 0;
                    $usage->outputTokens += $decoded['usage']['output_tokens'] ?? 0;
                    continue;
                }

                $content .= $chunk;

                yield $chunk;
            }

            $response = new AssistantMessage($content);
            $response->setUsage($usage);

            // Avoid double saving due to the recursive call.
            $last = $this->resolveChatHistory()->getLastMessage();
            if ($response->getRole() !== $last->getRole()) {
                $this->addToChatHistory($response);
            }

            $this->notify('stream-stop');

            return $response;
        } catch (\Throwable $exception) {
            $this->notify('error', new AgentError($exception));
            throw $exception;
        }
    }
}
