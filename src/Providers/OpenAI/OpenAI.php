<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Client;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolPropertyInterface;

class OpenAI implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://api.openai.com/v1';

    /**
     * System instructions.
     * https://platform.openai.com/docs/api-reference/chat/create
     */
    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        protected bool $strict_response = false,
        protected ?HttpClientOptions $httpOptions = null,
    ) {
        $config = [
            'base_uri' => \trim($this->baseUri, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]
        ];

        if ($this->httpOptions instanceof HttpClientOptions) {
            $config = $this->mergeHttpOptions($config, $this->httpOptions);
        }

        $this->client = new Client($config);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function generateToolsPayload(): array
    {
        return \array_map(function (ToolInterface $tool): array {
            $payload = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'required' => [],
                    ],
                ]
            ];

            $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
                $carry[$property->getName()] = $property->getJsonSchema();
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

    /**
     * @param array<string, mixed> $message
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $message): Message
    {
        $tools = \array_map(
            fn (array $item): ToolInterface => $this->findTool($item['function']['name'])
                ->setInputs(
                    \json_decode((string) $item['function']['arguments'], true)
                )
                ->setCallId($item['id']),
            $message['tool_calls']
        );

        $result = new ToolCallMessage(
            $message['content'],
            $tools
        );

        return $result->addMetadata('tool_calls', $message['tool_calls']);
    }
}
