<?php

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;

trait HandleChat
{
    /**
     * Send a message to the LLM.
     *
     * @param Message|array<Message> $messages
     * @throws GuzzleException
     */
    public function chat(array $messages): Message
    {
        // Include the system prompt
        if (isset($this->system)) {
            \array_unshift($messages, new AssistantMessage($this->system));
        }

        $mapper = new MessageMapper($messages);

        $json = [
            'model' => $this->model,
            'messages' => $mapper->map(),
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $json['tools'] = $this->generateToolsPayload();
        }

        $result = $this->client->post('v1/chat/completions', compact('json'))
            ->getBody()->getContents();

        $result = \json_decode($result, true);

        if ($result['choices'][0]['finish_reason'] === 'tool_calls') {
            $response = $this->createToolMessage($result['choices'][0]['message']);
        } else {
            $response = new AssistantMessage($result['choices'][0]['message']['content']);
        }

        if (\array_key_exists('usage', $result)) {
            $response->setUsage(
                new Usage($result['usage']['prompt_tokens'], $result['usage']['completion_tokens'])
            );
        }

        return $response;
    }
}
