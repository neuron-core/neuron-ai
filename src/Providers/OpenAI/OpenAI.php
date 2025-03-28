<?php

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Exception\GuzzleException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use GuzzleHttp\Client;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use Psr\Http\Message\StreamInterface;

class OpenAI implements AIProviderInterface
{
    use HandleWithTools;
    use HandleChat;
    use HandleStream;

    /**
     * The http client.
     *
     * @var Client
     */
    protected Client $client;

    /**
     * The main URL of the provider API.
     *
     * @var string
     */
    protected string $baseUri = 'https://api.openai.com';

    /**
     * System instructions.
     * https://platform.openai.com/docs/api-reference/chat/create
     *
     * @var ?string
     */
    protected ?string $system;

    public function __construct(
        protected string $key,
        protected string $model,
        protected int $max_tokens = 1024,
    ) {
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]
        ]);
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function generateToolsPayload(): array
    {
        return \array_map(function (ToolInterface $tool) {
            $payload = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                ]
            ];

            $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolProperty $property) {
                $carry[$property->getName()] = [
                    'description' => $property->getDescription(),
                    'type' => $property->getType(),
                ];

                if (!empty($property->getEnum())) {
                    $carry[$property->getName()]['enum'] = $property->getEnum();
                }

                return $carry;
            }, []);

            if (!empty($properties)) {
                $payload['function']['parameters'] = [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $tool->getRequiredProperties(),
                ];
            }

            return $payload;
        }, $this->tools);
    }

    protected function createToolMessage(array $message): Message
    {
        $tools = \array_map(function (array $item) {
            return $this->findTool($item['function']['name'])
                ->setInputs(json_decode($item['function']['arguments'], true))
                ->setCallId($item['id']);
        }, $message['tool_calls']);

        $result = new ToolCallMessage(
            $message['content'],
            $tools
        );

        return $result->addMetadata('tool_calls', $message['tool_calls']);
    }
}
