<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\Api\Parser\EventParsingIterator;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;

trait HandleStream
{
    /**
     * @throws ProviderException
     */
    public function stream(array|string $messages, callable $executeToolsCallback): \Generator
    {
        $payload = $this->createPayLoad($messages);
        $result = $this->bedrockRuntimeClient->converseStream($payload);

        $tools = [];
        foreach ($result as $eventParserIterator) {
            if (!$eventParserIterator instanceof EventParsingIterator) {
                continue;
            }

            $toolContent = null;
            foreach ($eventParserIterator as $event) {

                if (isset($event['metadata'])) {
                    yield \json_encode([
                        'usage' => [
                            'input_tokens' => $event['metadata']['usage']['inputTokens'] ?? 0,
                            'output_tokens' => $event['metadata']['usage']['outputTokens'] ?? 0,
                        ]
                    ]);
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
                    yield $event['contentBlockDelta']['delta']['text'];
                }
            }

            if ($toolContent !== null) {
                $tools[] = $this->createTool($toolContent);
            }
        }

        if (isset($stopReason) && $stopReason === 'tool_use' && \count($tools) > 0) {
            yield from $executeToolsCallback(
                new ToolCallMessage(null, $tools),
            );
        }
    }
}
