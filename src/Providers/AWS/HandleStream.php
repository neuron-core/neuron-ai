<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\Api\Parser\EventParsingIterator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Events\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;

trait HandleStream
{
    /**
     * Stream response from the LLM.
     *
     * Yields intermediate chunks during streaming and returns the final complete Message.
     *
     * @throws ProviderException
     */
    public function stream(array|string $messages): \Generator
    {
        $payload = $this->createPayLoad($messages);
        $result = $this->bedrockRuntimeClient->converseStream($payload);

        $tools = [];
        $text = '';
        $usage = new Usage(0, 0);
        $stopReason = null;

        foreach ($result as $eventParserIterator) {
            if (!$eventParserIterator instanceof EventParsingIterator) {
                continue;
            }

            $toolContent = null;
            foreach ($eventParserIterator as $event) {

                if (isset($event['metadata'])) {
                    $usage->inputTokens += $event['metadata']['usage']['inputTokens'] ?? 0;
                    $usage->outputTokens += $event['metadata']['usage']['outputTokens'] ?? 0;
                }

                if (isset($event['messageStop']['stopReason'])) {
                    $stopReason = $event['messageStop']['stopReason'];
                }

                if (isset($event['contentBlockStart']['start']['toolUse'])) {
                    $toolContent = $event['contentBlockStart']['start'];
                    $toolContent['toolUse']['input'] = '';
                    continue;
                }

                if ($toolContent !== null && isset($event['contentBlockDelta']['delta']['toolUse'])) {
                    $toolContent['toolUse']['input'] .= $event['contentBlockDelta']['delta']['toolUse']['input'];
                    continue;
                }

                if (isset($event['contentBlockDelta']['delta']['text'])) {
                    $textChunk = $event['contentBlockDelta']['delta']['text'];
                    $text .= $textChunk;
                    yield new TextChunk($textChunk);
                }
            }

            if ($toolContent !== null) {
                $tools[] = $this->createTool($toolContent);
            }
        }

        // Build final message
        if ($stopReason === 'tool_use' && \count($tools) > 0) {
            $message = new ToolCallMessage($text !== '' ? $text : null, $tools);
        } else {
            $blocks = [];
            if ($text !== '') {
                $blocks[] = new TextContent($text);
            }
            $message = new AssistantMessage($blocks);
        }

        $message->setUsage($usage);

        return $message;
    }
}
