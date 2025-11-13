<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use GuzzleHttp\Client;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

class Gemini implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://generativelanguage.googleapis.com/v1beta/models';

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
        protected ?HttpClientOptions $httpOptions = null,
    ) {
        $config = [
            // Since Gemini use colon ":" into the URL, guzzle fires an exception using base_uri configuration.
            //'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->key,
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
     * @param ContentBlock[] $blocks
     * @param array<int, array> $toolCalls
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $blocks, array $toolCalls): Message
    {
        $tools = \array_map(function (array $item): ToolInterface {
            // Gemini does not use ID. It uses the tool's name as a unique identifier.
            return $this->findTool($item['functionCall']['name'])
                ->setInputs($item['functionCall']['args'])
                ->setCallId($item['functionCall']['name']);
        }, $toolCalls);

        return new ToolCallMessage($blocks, $tools);
    }

    /**
     * Extract citations from Gemini's groundingMetadata.
     *
     * @param array<string, mixed> $groundingMetadata
     * @return Citation[]
     */
    protected function extractCitations(array $groundingMetadata): array
    {
        $citations = [];

        // Extract from groundingChunks (web search results)
        if (isset($groundingMetadata['groundingChunks'])) {
            foreach ($groundingMetadata['groundingChunks'] as $index => $chunk) {
                if (isset($chunk['web'])) {
                    $citations[] = new Citation(
                        id: 'gemini_chunk_'.$index,
                        source: $chunk['web']['uri'] ?? '',
                        title: $chunk['web']['title'] ?? null,
                        metadata: [
                            'chunk_index' => $index,
                            'provider' => 'gemini',
                        ]
                    );
                }
            }
        }

        // Extract from groundingSupports (links response text to sources)
        if (isset($groundingMetadata['groundingSupports'])) {
            foreach ($groundingMetadata['groundingSupports'] as $support) {
                $segment = $support['segment'] ?? null;
                $chunkIndices = $support['groundingChunkIndices'] ?? [];
                $confidenceScores = $support['confidenceScores'] ?? [];

                foreach ($chunkIndices as $idx => $chunkIndex) {
                    $sourceChunk = $groundingMetadata['groundingChunks'][$chunkIndex] ?? null;

                    if ($sourceChunk && isset($sourceChunk['web'])) {
                        $citations[] = new Citation(
                            id: 'gemini_support_'.\uniqid(),
                            source: $sourceChunk['web']['uri'] ?? '',
                            title: $sourceChunk['web']['title'] ?? null,
                            startIndex: $segment['startIndex'] ?? null,
                            endIndex: $segment['endIndex'] ?? null,
                            citedText: $segment['text'] ?? null,
                            metadata: [
                                'chunk_index' => $chunkIndex,
                                'confidence' => $confidenceScores[$idx] ?? null,
                                'provider' => 'gemini',
                            ]
                        );
                    }
                }
            }
        }

        return $citations;
    }
}
