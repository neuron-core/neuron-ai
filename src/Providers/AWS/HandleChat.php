<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\ProviderResponse;

use function end;

trait HandleChat
{
    public function chat(Message ...$messages): ProviderResponse
    {
        $message = $this->chatAsync(...$messages)->wait();
        return new ProviderResponse(message: $message);
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
                $blocks = $this->mapResponseContent($result['output']['message']['content'] ?? []);

                if ($stopReason === 'tool_use') {
                    $tools = [];
                    foreach ($result['output']['message']['content'] ?? [] as $toolContent) {
                        if (isset($toolContent['toolUse'])) {
                            $tools[] = $this->createTool($toolContent);
                        }
                    }

                    $message = new ToolCallMessage($blocks, $tools);
                    $message->setUsage($usage);
                    $message->setStopReason($stopReason);
                    return $message;
                }

                $message = new AssistantMessage($blocks);
                $message->setUsage($usage);
                $message->setStopReason($stopReason);
                return $message;
            });
    }

    /**
     * @param array<int, array<string, mixed>> $contents
     * @return ContentBlockInterface[]
     */
    protected function mapResponseContent(array $contents): array
    {
        $blocks = [];

        foreach ($contents as $content) {
            if (isset($content['text'])) {
                $lastBlock = end($blocks);

                if ($lastBlock instanceof TextContent && !$lastBlock instanceof ReasoningContent) {
                    $lastBlock->accumulateContent($content['text']);
                } else {
                    $blocks[] = new TextContent($content['text']);
                }
                continue;
            }

            if (isset($content['reasoningContent']['reasoningText'])) {
                $reasoningText = $content['reasoningContent']['reasoningText'];
                $blocks[] = new ReasoningContent(
                    $reasoningText['text'],
                    $reasoningText['signature'] ?? null,
                );
            }
        }

        return $blocks;
    }
}
