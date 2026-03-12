<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function json_decode;
use function uniqid;

class OpenAI implements AIProviderInterface
{
    use HasHttpClient;
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
     */
    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;
    protected ToolMapperInterface $toolPayloadMapper;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        protected bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        // Use the provided client or create default Guzzle client
        // Provider always configures authentication and base URI
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]);
    }

    protected function createChatHttpRequest(array $payload): HttpRequest
    {
        return HttpRequest::post(
            uri: 'chat/completions',
            body: $payload
        );
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ??= new ToolMapper();
    }

    /**
     * @param array<int, array> $toolCalls
     * @param ContentBlockInterface|ContentBlockInterface[]|null $blocks
     *
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, array|ContentBlockInterface|null $blocks = null): ToolCallMessage
    {
        $tools = array_map(
            fn (array $item): ToolInterface => $this->findTool($item['function']['name'])
                ->setInputs(
                    json_decode((string) $item['function']['arguments'], true)
                )
                ->setCallId($item['id']),
            $toolCalls
        );

        $result = new ToolCallMessage($blocks, $tools);
        $result->addMetadata('tool_calls', $toolCalls);

        return $result;
    }

    /**
     * Hook for enriching messages with provider-specific data.
     * Override in child classes to add metadata like reasoning_content.
     */
    protected function enrichMessage(AssistantMessage $message, ?array $response = null): AssistantMessage
    {
        // Apply any accumulated streaming metadata if available
        if (isset($this->streamState)) {
            foreach ($this->streamState->getMetadata() as $key => $value) {
                if ($message->getMetadata($key) === null) {
                    $message->addMetadata($key, $value);
                }
            }
        }

        return $message;
    }
}
