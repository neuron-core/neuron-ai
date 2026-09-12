<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;

use function base64_encode;

trait HandleChat
{
    public function chat(Message ...$messages): Message
    {
        return $this->chatAsync(...$messages)->wait();
    }

    public function chatAsync(Message ...$messages): PromiseInterface
    {
        $payload = $this->createPayLoad($messages);

        return $this->bedrockRuntimeClient
            ->converseAsync($payload)
            ->then(function (ResultInterface $result): ToolCallMessage|AssistantMessage {
                $usage = new Usage(
                    $result['usage']['inputTokens'] ?? 0,
                    $result['usage']['outputTokens'] ?? 0,
                    $result['usage']['cacheReadInputTokens'] ?? 0,
                );

                $stopReason = $result['stopReason'] ?? '';
                $message = $this->createResponseMessage($result['output']['message']['content'] ?? []);
                $message->setUsage($usage);
                $message->setStopReason($stopReason);
                return $message;
            });
    }

    /**
     * @param array<int, array<string, mixed>> $contents
     * @throws ProviderException
     */
    protected function createResponseMessage(array $contents): AssistantMessage
    {
        $blocks = [];
        $tools = [];
        $toolPositions = [];
        $redactedReasoning = [];

        foreach ($contents as $index => $content) {
            if (isset($content['text'])) {
                $blocks[] = new TextContent($content['text']);
                continue;
            }

            if (isset($content['reasoningContent']['reasoningText'])) {
                $reasoningText = $content['reasoningContent']['reasoningText'];
                $blocks[] = new ReasoningContent(
                    $reasoningText['text'],
                    $reasoningText['signature'] ?? null,
                );
            }

            if (isset($content['reasoningContent']['redactedContent'])) {
                // The AWS SDK returns binary bytes; history stores JSON.
                $redactedReasoning[$index] = base64_encode($content['reasoningContent']['redactedContent']);
            }

            if (isset($content['toolUse'])) {
                $tools[] = $this->createTool($content);
                $toolPositions[] = $index;
            }
        }

        $message = $tools === [] ? new AssistantMessage($blocks) : new ToolCallMessage($blocks, $tools);
        if ($redactedReasoning !== []) {
            $message->addMetadata('aws_redacted_reasoning', $redactedReasoning);
        }
        if ($toolPositions !== []) {
            $message->addMetadata('aws_tool_positions', $toolPositions);
        }

        return $message;
    }
}
