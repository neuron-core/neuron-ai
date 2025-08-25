<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;

trait HandleChat
{
    public function chat(array $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array $messages): PromiseInterface
    {
        $payload = $this->createPayLoad($messages);

        return $this->bedrockRuntimeClient
            ->converseAsync($payload)
            ->then(function (ResultInterface $response): ToolCallMessage|AssistantMessage {
                $usage = new Usage(
                    $response['usage']['inputTokens'] ?? 0,
                    $response['usage']['outputTokens'] ?? 0,
                );

                $stopReason = $response['stopReason'] ?? '';
                if ($stopReason === 'tool_use') {
                    $tools = [];
                    foreach ($response['output']['message']['content'] ?? [] as $toolContent) {
                        if (isset($toolContent['toolUse'])) {
                            $tools[] = $this->createTool($toolContent);
                        }
                    }

                    $toolCallMessage = new ToolCallMessage(null, $tools);
                    $toolCallMessage->setUsage($usage);
                    return $toolCallMessage;
                }

                $responseText = '';
                foreach ($response['output']['message']['content'] ?? [] as $content) {
                    if (isset($content['text'])) {
                        $responseText .= $content['text'];
                    }
                }

                $assistantMessage = new AssistantMessage($responseText);
                $assistantMessage->setUsage($usage);
                return $assistantMessage;
            });
    }
}
