<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Client;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function json_decode;
use function trim;
use function uniqid;

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
            'base_uri' => trim($this->baseUri, '/').'/',
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

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolPayloadMapper();
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

    /**
     * Extract citations from OpenAI's content annotations.
     *
     * @param array<int, array<string, mixed>> $annotations
     * @return Citation[]
     */
    protected function extractCitations(array $annotations): array
    {
        $citations = [];

        foreach ($annotations as $annotation) {
            $type = $annotation['type'] ?? null;

            if ($type === 'file_citation') {
                $fileCitation = $annotation['file_citation'] ?? [];
                $citations[] = new Citation(
                    id: $fileCitation['file_id'] ?? uniqid('openai_file_'),
                    source: $fileCitation['file_id'] ?? '',
                    startIndex: $annotation['start_index'] ?? null,
                    endIndex: $annotation['end_index'] ?? null,
                    citedText: $annotation['text'] ?? null,
                    metadata: [
                        'type' => 'file_citation',
                        'quote' => $fileCitation['quote'] ?? null,
                        'provider' => 'openai',
                    ]
                );
            } elseif ($type === 'file_path') {
                $filePath = $annotation['file_path'] ?? [];
                $citations[] = new Citation(
                    id: $filePath['file_id'] ?? uniqid('openai_path_'),
                    source: $filePath['file_id'] ?? '',
                    startIndex: $annotation['start_index'] ?? null,
                    endIndex: $annotation['end_index'] ?? null,
                    citedText: $annotation['text'] ?? null,
                    metadata: [
                        'type' => 'file_path',
                        'provider' => 'openai',
                    ]
                );
            }
        }

        return $citations;
    }
}
