<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;

use function array_unshift;

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
            $response = new AssistantMessage(
                new TextContent($result['message']['content'][0]['text'])
            );
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
