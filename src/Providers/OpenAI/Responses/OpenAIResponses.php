<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Client;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

class OpenAIResponses implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleResponses;
    use HandleResponsesStream;
    use HandleResponsesStructured;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://api.openai.com/v1';

    /**
     * System instructions.
     */
    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;
    protected ToolPayloadMapperInterface $toolPayloadMapper;

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
        return $this->messageMapper ?? $this->messageMapper = new MessageMapperResponses();
    }

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolPayloadMapperResponses();
    }

    protected function createAssistantMessage(array $response): AssistantMessage
    {
        $messages = \array_values(
            \array_filter(
                $response['output'],
                fn (array $message): bool => $message['type'] === 'message' && $message['role'] == MessageRole::ASSISTANT->value
            )
        );

        $content = $messages[0]['content'][0];

        $message = new AssistantMessage($content['text']);

        if (isset($content['annotations'])) {
            $message->addMetadata('annotations', $content['annotations']);
        }

        if (\array_key_exists('usage', $response)) {
            $message->setUsage(
                new Usage($response['usage']['input_tokens'], $response['usage']['output_tokens'])
            );
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $toolCalls
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, ?array $usage): ToolCallMessage
    {
        $tools = \array_map(
            fn (array $item): ToolInterface => $this->findTool($item['name'])
                ->setInputs(
                    \json_decode((string) $item['arguments'], true)
                )
                ->setCallId($item['call_id']),
            $toolCalls
        );

        $message = new ToolCallMessage('', $tools);

        if (!\is_null($usage)) {
            $message->setUsage(
                new Usage($usage['input_tokens'], $usage['output_tokens'])
            );
        }

        return $message;
    }
}
