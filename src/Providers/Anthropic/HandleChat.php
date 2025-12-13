<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HttpClient\HttpException;
use NeuronAI\Providers\HttpClient\HttpRequest;

use function array_key_exists;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(array $messages): Message
    {
        $json = [
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if (isset($this->system)) {
            $json['system'] = $this->system;
        }

        if (!empty($this->tools)) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        $response = $this->httpClient->request(
            new HttpRequest(
                method: 'POST',
                uri: 'messages',
                body: $json
            )
        );

        return $this->processChatResult($response->json());
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        $blocks = [];
        $toolCalls = [];
        foreach ($result['content'] as $content) {
            if ($content['type'] === 'thinking') {
                $blocks[] = new ReasoningContent($content['thinking'], $content['signature']);
                continue;
            }

            if ($content['type'] === 'text') {
                $blocks[] = new TextContent($content['text']);
                continue;
            }

            if ($content['type'] === 'tool_use') {
                $toolCalls[] = $content;
            }
        }

        if ($toolCalls !== []) {
            $message = $this->createToolCallMessage($toolCalls, $blocks);
        } else {
            $message = new AssistantMessage($blocks);
            $citations = $this->extractCitations($result['content']);
            if (!empty($citations)) {
                $message->addMetadata('citations', $citations);
            }
        }

        // Attach the usage for the current interaction
        if (array_key_exists('usage', $result)) {
            $message->setUsage(
                new Usage(
                    $result['usage']['input_tokens'],
                    $result['usage']['output_tokens']
                )
            );
        }

        return $message;
    }
}
