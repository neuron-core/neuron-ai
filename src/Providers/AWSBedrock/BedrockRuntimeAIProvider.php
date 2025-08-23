<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWSBedrock;

use Aws\Api\Parser\EventParsingIterator;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\ResultInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

class BedrockRuntimeAIProvider implements AIProviderInterface
{
    use HandleWithTools;
    use HandleStructured;

    protected ?string $system = null;

    public function __construct(
        protected BedrockRuntimeClient $bedrockRuntimeClient,
        protected MessageMapperInterface $messageMapper,
        protected string $model,
        protected ?int $maxTokens = null,
        protected ?float $temperature = null,
        protected ?float $topP = null,
    ) {
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;

        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper;
    }

    public function chat(array $messages): Message
    {
        return $this->chatAsync($messages)->wait();
    }

    public function chatAsync(array $messages): PromiseInterface
    {
        $payload = $this->createPayLoad($messages);

        return $this->bedrockRuntimeClient
            ->converseAsync($payload)
            ->then(function (ResultInterface $response) {
                $usage = new Usage(
                    $response['usage']['inputTokens'] ?? 0,
                    $response['usage']['outputTokens'] ?? 0,
                );

                $stopReason = $response['stopReason'] ?? '';
                if ($stopReason === 'tool_use') {
                    $tools = [];
                    foreach ($response['output']['message']['content'] ?? [] as $toolContent) {
                        if (isset($toolContent['toolUse'])) {
                            $tools[] = $this->createTool($toolContent);
                        }
                    }

                    $toolCallMessage = new ToolCallMessage(null, $tools);
                    $toolCallMessage->setUsage($usage);
                    return $toolCallMessage;
                }

                $responseText = '';
                foreach ($response['output']['message']['content'] ?? [] as $content) {
                    if (isset($content['text'])) {
                        $responseText .= $content['text'];
                    }
                }

                $assistantMessage = new AssistantMessage($responseText);
                $assistantMessage->setUsage($usage);
                return $assistantMessage;
            });
    }

    public function stream(array|string $messages, callable $executeToolsCallback): \Generator
    {
        $payload = $this->createPayLoad($messages);
        $result = $this->bedrockRuntimeClient->converseStream($payload);

        $tools = [];
        foreach ($result as $eventParserIterator) {
            if (!$eventParserIterator instanceof EventParsingIterator) {
                continue;
            }

            $toolContent = null;
            foreach ($eventParserIterator as $event) {

                if (isset($event['metadata'])) {
                    yield \json_encode([
                        'usage' => [
                            'input_tokens' => $event['metadata']['usage']['inputTokens'] ?? 0,
                            'output_tokens' => $event['metadata']['usage']['outputTokens'] ?? 0,
                        ]
                    ]);
                }

                if (isset($event['messageStop']['stopReason'])) {
                    $stopReason = $event['messageStop']['stopReason'];
                }

                if (isset($event['contentBlockStart']['start']['toolUse'])) {
                    $toolContent = $event['contentBlockStart']['start'];
                    $toolContent['toolUse']['input'] = '';
                    continue;
                }

                if ($toolContent !== null && isset($event['contentBlockDelta']['delta']['toolUse'])) {
                    $toolContent['toolUse']['input'] .= $event['contentBlockDelta']['delta']['toolUse']['input'];
                    continue;
                }

                if (isset($event['contentBlockDelta']['delta']['text'])) {
                    yield $event['contentBlockDelta']['delta']['text'];
                }
            }

            if ($toolContent !== null) {
                $tools[] = $this->createTool($toolContent);
            }
        }

        if (isset($stopReason) && $stopReason === 'tool_use' && \count($tools) > 0) {
            yield from $executeToolsCallback(
                new ToolCallMessage(null, $tools),
            );
        }
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

        if ($this->maxTokens !== null) {
            $payload['inferenceConfig']['maxTokens'] = $this->maxTokens;
        }

        if ($this->temperature !== null) {
            $payload['inferenceConfig']['temperature'] = $this->temperature;
        }

        if ($this->topP !== null) {
            $payload['inferenceConfig']['topP'] = $this->topP;
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
        // no need to set client for AWSBedrockAIProvider since it uses its own BedrockRuntimeClient
        return $this;
    }
}
