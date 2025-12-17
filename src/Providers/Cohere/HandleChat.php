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
     * @throws HttpException
     */
    public function chat(array $messages): Message
    {
        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $json = [
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
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
        if ($result['finish_reason'] === 'TOOL_CALL') {
            $block = isset($result['message']['content'])
                ? new TextContent($result['message']['content'])
                : null;
            $response = $this->createToolCallMessage($result['message']['tool_calls'], $block);
        } else {
            $response = new AssistantMessage($result['message']['content']);
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
