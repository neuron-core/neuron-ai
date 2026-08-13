<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

use function uniqid;
use function array_values;

class Gemini implements AIProviderInterface
{
    use HasHttpClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

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
        ?HttpClientInterface $httpClient = null,
        protected string $baseUri = 'https://generativelanguage.googleapis.com/v1beta/models'
    ) {
        // Use provided client or create default Guzzle client
        // Provider always configures authentication headers
        // Note: Gemini doesn't use base_uri due to colon ":" in URL pattern
        $this->httpClient = ($httpClient ?? new CurlHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->key,
            ]);
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->system = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;
        return $this;
    }

    protected function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    protected function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ??= new ToolMapper();
    }

    /**
     * @param ContentBlockInterface[] $blocks
     * @param array<int, array> $toolCalls
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $blocks, array $toolCalls): ToolCallMessage
    {
        $toolCalls = array_values($toolCalls);

        $tools = [];
        foreach ($toolCalls as $index => $item) {
            // Gemini identifies calls by tool name on the wire and only optionally provides
            // an id, but the framework treats callId as per-call identity (memoization,
            // approval decisions, stream protocols) — parallel calls of the same tool must
            // never share it. Prefer the API id, otherwise synthesize a locally-unique one;
            // the mapper never echoes callId back to Gemini, so a synthetic id is wire-safe.
            $tools[] = $this->newToolCall(
                $item['functionCall']['name'],
                $item['functionCall']['id'] ?? uniqid($item['functionCall']['name'].'_'.$index.'_'),
                $item['functionCall']['args'],
            );
        }

        $message = new ToolCallMessage($blocks, $tools);

        if (isset($toolCalls[0]['thoughtSignature'])) {
            $message->addMetadata('thought_signature', $toolCalls[0]['thoughtSignature']);
        }

        return $message;
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
                            id: 'gemini_support_'.uniqid(),
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
