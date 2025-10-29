<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use GuzzleHttp\Client;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;

class Anthropic implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://api.anthropic.com/v1/';

    /**
     * System instructions.
     * https://docs.anthropic.com/claude/docs/system-prompts#how-to-use-system-prompts
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
        protected string $version = '2023-06-01',
        protected int $max_tokens = 8192,
        protected array $parameters = [],
        protected ?HttpClientOptions $httpOptions = null,
    ) {
        $config = [
            'base_uri' => \trim($this->baseUri, '/').'/',
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->key,
                'anthropic-version' => $version,
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

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolPayloadMapper();
    }

    /**
     * @param array<string, mixed> $message
     * @throws ProviderException
     */
    public function createToolCallMessage(array $message): Message
    {
        $tool = $this->findTool($message['name'])
            ->setInputs($message['input'])
            ->setCallId($message['id']);

        // During serialization and deserialization PHP convert the original empty object {} to empty array []
        // causing an error on the Anthropic API. If there are no inputs, we need to restore the empty JSON object.
        if (empty($message['input'])) {
            $message['input'] = new \stdClass();
        }

        return new ToolCallMessage(
            [$message],
            [$tool] // Anthropic call one tool at a time. So we pass an array with one element.
        );
    }
}
