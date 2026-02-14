<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;

use function array_filter;

/**
 * Inspired by Andrew Monty - https://github.com/AndrewMonty
 */
trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        $body = [
            'model' => $this->model,
            'input' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach the system prompt
        if (isset($this->system)) {
            $body['instructions'] = $this->system;
        }

        // Attach tools
        if (!empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'responses',
                body: $body
            )
        );

        return $this->processChatResult($response->json());
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        $toolCalls = array_filter($result['output'], fn (array $item): bool => $item['type'] == 'function_call');

        $usage = new Usage($result['usage']['input_tokens'] ?? 0, $result['usage']['output_tokens'] ?? 0);

        if ($toolCalls !== []) {
            $message = $this->createToolCallMessage($toolCalls)->setUsage($usage);
        } else {
            $message = $this->createAssistantMessage($result)->setUsage($usage);
        }

        if (isset($result['status'])) {
            $message->setStopReason($result['status']);
        }

        return $message;
    }
}
