<?php

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleClient;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use GuzzleHttp\Client;

class Anthropic implements AIProviderInterface
{
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleClient;

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
    protected string $baseUri = 'https://api.anthropic.com/v1/';

    /**
     * System instructions.
     * https://docs.anthropic.com/claude/docs/system-prompts#how-to-use-system-prompts
     *
     * @var string|null
     */
    protected ?string $system;

    /**
     * AnthropicClaude constructor.
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected string $version = '2023-06-01',
        protected int $max_tokens = 8192,
        protected array $parameters = [],
    ) {
        $this->client = new Client([
            'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->key,
                'anthropic-version' => $version,
            ]
        ]);
    }

    /**
     * @inerhitDoc
     */
    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function generateToolsPayload(): array
    {
        return \array_map(function (ToolInterface $tool) {
            \array_reduce($tool->getProperties(), function ($carry, ToolProperty $property) {
                $carry[$property->getName()] = [
                    'type' => $property->getType(),
                    'description' => $property->getDescription(),
                ];

                if (!empty($property->getEnum())) {
                    $carry[$property->getName()]['enum'] = $property->getEnum();
                }

                return $carry;
            }, []);

            return [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'input_schema' => [
                    'type' => 'object',
                    'properties' => !empty($properties) ? $properties : null,
                    'required' => $tool->getRequiredProperties(),
                ],
            ];
        }, $this->tools);
    }

    public function createToolMessage(array $content): Message
    {
        $tool = $this->findTool($content['name'])
            ->setInputs($content['input']??[])
            ->setCallId($content['id']);

        $content['input'] ??= (object)[];

        return new ToolCallMessage(
            [$content],
            [$tool] // Anthropic call one tool at a time. So we pass an array with one element.
        );
    }
}
