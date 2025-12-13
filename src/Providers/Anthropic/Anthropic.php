<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\HasHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\HttpClient\HttpClientInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function is_array;
use function mb_strlen;
use function trim;
use function uniqid;

class Anthropic implements AIProviderInterface
{
    use HasHttpClient;
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
        ?HttpClientInterface $httpClient = null,
    ) {
        // Use provided client or create default Guzzle client
        // Provider always configures authentication and base URI
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/') . '/')
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $this->key,
                'anthropic-version' => $version,
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

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolPayloadMapper();
    }

    /**
     * @param string|ContentBlockInterface[]|null $content
     * @throws ProviderException
     */
    public function createToolCallMessage(array $toolCalls, string|array|null $content = null): ToolCallMessage
    {
        $tools = array_map(fn (array $tool): ToolInterface => $this->findTool($tool['name'])
            ->setInputs($tool['input'])
            ->setCallId($tool['id']), $toolCalls);

        return new ToolCallMessage($content, $tools);
    }

    /**
     * Extract citations from Anthropic's content blocks.
     *
     * @param array<int, array<string, mixed>> $contentBlocks
     * @return Citation[]
     */
    protected function extractCitations(array $contentBlocks): array
    {
        $citations = [];
        $textOffset = 0;

        foreach ($contentBlocks as $index => $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $text = $block['text'] ?? '';
                $textLength = mb_strlen($text);

                // Check if this text block has citations metadata
                if (isset($block['citations']) && is_array($block['citations'])) {
                    foreach ($block['citations'] as $citation) {
                        $citations[] = new Citation(
                            id: $citation['id'] ?? uniqid('anthropic_'),
                            source: $citation['source'] ?? '',
                            title: $citation['title'] ?? null,
                            startIndex: ($citation['start_index'] ?? 0) + $textOffset,
                            endIndex: ($citation['end_index'] ?? $textLength) + $textOffset,
                            citedText: $citation['text'] ?? null,
                            metadata: [
                                'block_index' => $index,
                                'provider' => 'anthropic',
                            ]
                        );
                    }
                }

                $textOffset += $textLength;
            }
        }

        return $citations;
    }
}
