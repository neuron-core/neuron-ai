<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\Api\Parser\EventParsingIterator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_ConverseStream.html#API_runtime_ConverseStream_ResponseSyntax
     *
     * @throws ProviderException
     */
    public function stream(array|string $messages): \Generator
    {
        $payload = $this->createPayLoad($messages);
        $result = $this->bedrockRuntimeClient->converseStream($payload);

        $this->streamState = new StreamState();

        $tools = [];
        $stopReason = null;

        foreach ($result as $eventParserIterator) {
            if (!$eventParserIterator instanceof EventParsingIterator) {
                continue;
            }

            $toolContent = null;
            foreach ($eventParserIterator as $event) {

                if (isset($event['metadata'])) {
                    $this->streamState->addInputTokens($event['metadata']['usage']['inputTokens'] ?? 0);
                    $this->streamState->addOutputTokens($event['metadata']['usage']['outputTokens'] ?? 0);
                }

                if (isset($event['messageStop']['stopReason'])) {
                    $stopReason = $event['messageStop']['stopReason'];
                }

                if (isset($event['contentBlockStart']['start']['toolUse'])) {
                    $toolContent = $event['contentBlockStart']['start'];
                    $toolContent['toolUse']['input'] = '';
                    continue;
                }

                if (isset($event['contentBlockDelta']['delta']['text'])) {
                    $textChunk = $event['contentBlockDelta']['delta']['text'];
                    $this->streamState->updateContentBlock($event['contentBlockDelta']['contentBlockIndex'], $textChunk);
                    yield new TextChunk($this->streamState->messageId(), $textChunk);
                }

                if (isset($event['contentBlockDelta']['delta']['reasoningContent'])) {
                    $reasoningChunk = $event['contentBlockDelta']['delta']['reasoningContent']['text'];
                    $this->streamState->updateContentBlock(
                        $event['contentBlockDelta']['contentBlockIndex'],
                        new ReasoningContent($reasoningChunk, $event['contentBlockDelta']['delta']['reasoningContent']['signature'])
                    );
                    yield new ReasoningChunk($this->streamState->messageId(), $reasoningChunk);
                    continue;
                }

                if ($toolContent !== null && isset($event['contentBlockDelta']['delta']['toolUse'])) {
                    $toolContent['toolUse']['input'] .= $event['contentBlockDelta']['delta']['toolUse']['input'];
                }
            }

            if ($toolContent !== null) {
                $tools[] = $this->createTool($toolContent);
            }
        }

        // Build final message
        if ($stopReason === 'tool_use' && \count($tools) > 0) {
            $message = new ToolCallMessage($this->streamState->getContentBlocks(), $tools);
        } else {
            $message = new AssistantMessage($this->streamState->getContentBlocks());
        }

        $message->setUsage($this->streamState->getUsage());

        return $message;
    }
}
