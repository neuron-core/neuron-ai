<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;

use function array_filter;
use function array_map;

trait HandleChat
{
    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        // A tool call answer carries its text in tool_plan and may have no content at all
        $blocks = $this->extractContent($result['message']['content'] ?? []);

        if ($result['finish_reason'] === 'TOOL_CALL') {
            $blocks[] = new TextContent($result['message']['tool_plan'] ?? '');
            $response = $this->createToolCallMessage($result['message']['tool_calls'], $blocks);
        } else {
            $response = new AssistantMessage($blocks);
        }

        if (isset($result['usage'])) {
            $response->setUsage(
                new Usage(
                    $result['usage']['tokens']['input_tokens'] ?? 0,
                    $result['usage']['tokens']['output_tokens'] ?? 0
                )
            );
        }

        $response->setStopReason($result['finish_reason']);

        return $response;
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @return ContentBlockInterface[]
     */
    protected function extractContent(array $content): array
    {
        $blocks = array_map(fn (array $item): ?ContentBlockInterface => match ($item['type']) {
            'text' => new TextContent($item['text']),
            'thinking' => new ReasoningContent($item['thinking']),
            default => null,
        }, $content);

        return array_filter($blocks);
    }
}
