<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\Client;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

class BedrockRuntime implements AIProviderInterface
{
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;
    protected ToolPayloadMapperInterface $toolPayloadMapper;

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

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolPayloadMapper();
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

        $tools = $this->toolPayloadMapper()->map($this->tools);

        if ($tools !== []) {
            $payload['toolConfig']['tools'] = $tools;
        }

        return $payload;
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
