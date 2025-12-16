<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\ToolMapper as OpenAIToolMapper;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function json_decode;
use function trim;

/**
 * https://docs.cohere.com/reference/chat
 */
class Cohere implements AIProviderInterface
{
    use HasHttpClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    protected ?string $system = null;

    protected MessageMapperInterface $messageMapper;
    protected ToolMapperInterface $toolPayloadMapper;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected string $baseUri = 'https://api.cohere.ai/v2',
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/') . '/')
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->key,
            ]);
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

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new OpenAIToolMapper();
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

        return new ToolCallMessage($blocks, $tools);
    }
}
