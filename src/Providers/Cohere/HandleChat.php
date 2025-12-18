<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;

use function array_map;
use function array_filter;

trait HandleChat
{
    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        if ($result['finish_reason'] === 'TOOL_CALL') {
            $block = isset($result['message']['content'])
                ? new TextContent($result['message']['content'])
                : null;
            $response = $this->createToolCallMessage($result['message']['tool_calls'], $block);
        } else {
            $blocks = array_map(fn (array $content): ?ContentBlockInterface => match ($content['type']) {
                'text' => new TextContent($content['text']),
                'thinking' => new ReasoningContent($content['thinking']),
                default => null,
            }, $result['message']['content'] ?? []);

            $response = new AssistantMessage(array_filter($blocks));
        }

        if (isset($result['usage'])) {
            $response->setUsage(
                new Usage(
                    $result['usage']['tokens']['input_tokens'] ?? 0,
                    $result['usage']['tokens']['output_tokens'] ?? 0
                )
            );
        }

        return $response;
    }
}
