<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use Psr\Http\Message\ResponseInterface;

use function array_filter;
use function json_decode;

/**
 * Inspired by Andrew Monty - https://github.com/AndrewMonty
 */
trait HandleChat
{
    public function chat(array $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array $messages): PromiseInterface
    {
        $json = [
            'model' => $this->model,
            'input' => $this->messageMapper()->map($messages),
            ...$this->parameters
        ];

        // Attach the system prompt
        if (isset($this->system)) {
            $json['instructions'] = $this->system;
        }

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->client->postAsync('responses', [RequestOptions::JSON => $json])
            ->then(function (ResponseInterface $result) {
                $result = json_decode($result->getBody()->getContents(), true);
                return $this->processChatResult($result);
            });
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        $toolCalls = array_filter($result['output'], fn (array $item): bool => $item['type'] == 'function_call');

        $usage = new Usage($result['usage']['input_tokens'] ?? 0, $result['usage']['output_tokens'] ?? 0);

        if ($toolCalls !== []) {
            return $this->createToolCallMessage($toolCalls)->setUsage($usage);
        }

        return $this->createAssistantMessage($result)->setUsage($usage);
    }
}
