<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\Client;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

class BedrockRuntime implements AIProviderInterface
{
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;

    public function __construct(
        protected BedrockRuntimeClient $bedrockRuntimeClient,
        protected string $model,
        protected array $inferenceConfig = [],
    ) {
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;

        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper();
    }

    protected function createPayLoad(array $messages): array
    {
        $payload = [
            'modelId' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            'system' => [[
                'text' => $this->system,
            ]],
        ];

        if (\count($this->inferenceConfig) > 0) {
            $payload['inferenceConfig'] = $this->inferenceConfig;
        }

        $toolSpecs = $this->generateToolsPayload();

        if (\count($toolSpecs) > 0) {
            $payload['toolConfig']['tools'] = $toolSpecs;
        }

        return $payload;
    }

    protected function generateToolsPayload(): array
    {
        return \array_map(function (ToolInterface $tool): array {
            $payload = [
                'toolSpec' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'inputSchema' => [
                        'json' => [
                            'type' => 'object',
                            'properties' => new \stdClass(),
                            'required' => [],
                        ]
                    ],
                ],
            ];

            $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
                $carry[$property->getName()] = $property->getJsonSchema();
                return $carry;
            }, []);

            if (!empty($properties)) {
                $payload['toolSpec']['inputSchema']['json'] = [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $tool->getRequiredProperties(),
                ];
            }

            return $payload;
        }, $this->tools);
    }

    /**
     * @throws ProviderException
     */
    protected function createTool(array $toolContent): ToolInterface
    {
        $toolUse = $toolContent['toolUse'];
        $tool = $this->findTool($toolUse['name']);
        $tool->setCallId($toolUse['toolUseId']);
        if (\is_string($toolUse['input'])) {
            $toolUse['input'] = \json_decode($toolUse['input'], true);
        }
        $tool->setInputs($toolUse['input'] ?? []);
        return $tool;
    }

    public function setClient(Client $client): AIProviderInterface
    {
        // no need to set the client since it uses its own BedrockRuntimeClient
        return $this;
    }
}
