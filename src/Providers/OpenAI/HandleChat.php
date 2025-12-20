<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\HttpException;

use function array_unshift;
use function is_array;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(array|Message $messages): Message
    {
        $messages = is_array($messages) ? $messages : [$messages];

        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->request(
            $this->createChatHttpRequest($json)
        );

        return $this->processChatResult($response->json());
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        if ($result['choices'][0]['finish_reason'] === 'tool_calls') {
            $block = isset($result['choices'][0]['message']['content'])
                ? new TextContent($result['choices'][0]['message']['content'])
                : null;
            $response = $this->createToolCallMessage($result['choices'][0]['message']['tool_calls'], $block);
        } else {
            $response = new AssistantMessage($result['choices'][0]['message']['content']);
        }

        if (isset($result['usage'])) {
            $response->setUsage(
                new Usage($result['usage']['prompt_tokens'], $result['usage']['completion_tokens'])
            );
        }

        // Extract citations from content annotations
        $message = $result['choices'][0]['message'];
        if (isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $contentBlock) {
                if (isset($contentBlock['annotations']) && is_array($contentBlock['annotations'])) {
                    $citations = $this->extractCitations($contentBlock['annotations']);
                    if (!empty($citations)) {
                        $response->addMetadata('citations', $citations);
                    }
                }
            }
        }

        return $this->enrichMessage($response, $result);
    }
}
