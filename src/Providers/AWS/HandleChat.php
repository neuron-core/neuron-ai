<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;

use function is_array;

trait HandleChat
{
    public function chat(array|Message $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array|Message $messages): PromiseInterface
    {
        $payload = $this->createPayLoad(
            is_array($messages) ? $messages : [$messages]
        );

        return $this->bedrockRuntimeClient
            ->converseAsync($payload)
            ->then(function (ResultInterface $result): ToolCallMessage|AssistantMessage {
                $usage = new Usage(
                    $result['usage']['inputTokens'] ?? 0,
                    $result['usage']['outputTokens'] ?? 0,
                );

                $stopReason = $result['stopReason'] ?? '';
                if ($stopReason === 'tool_use') {
                    $tools = [];
                    foreach ($result['output']['message']['content'] ?? [] as $toolContent) {
                        if (isset($toolContent['toolUse'])) {
                            $tools[] = $this->createTool($toolContent);
                        }
                    }

                    $message = new ToolCallMessage(tools: $tools);
                    $message->setUsage($usage);
                    return $message;
                }

                $text = '';
                foreach ($result['output']['message']['content'] ?? [] as $content) {
                    if (isset($content['text'])) {
                        $text .= $content['text'];
                    }
                }

                $message = new AssistantMessage($text);
                $message->setUsage($usage);
                return $message;
            });
    }
}
