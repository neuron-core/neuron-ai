<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

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

class Ollama implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $url, // http://localhost:11434/api
        protected string $model,
        protected array $parameters = [],
        protected ?HttpClientOptions $httpOptions = null,
    ) {
        $config = [
            'base_uri' => \trim($this->url, '/').'/',
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
                    ]
                ],
            ];

            $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolPropertyInterface $property): array {
                $carry[$property->getName()] = [
                    'type' => $property->getType()->value,
                    'description' => $property->getDescription(),
                ];

                return $carry;
            }, []);

            if (! empty($properties)) {
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
        $tools = \array_map(fn (array $item): ToolInterface => $this->findTool($item['function']['name'])
            ->setInputs($item['function']['arguments']), $message['tool_calls']);

        $result = new ToolCallMessage(
            $message['content'],
            $tools
        );

        return $result->addMetadata('tool_calls', $message['tool_calls']);
    }
}
