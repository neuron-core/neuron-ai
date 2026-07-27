<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function is_array;
use function mb_strlen;
use function uniqid;
use function array_values;

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
     */
    protected ?string $system = null;

    /**
     * System prompt blocks for prompt caching.
     */
    protected ?array $systemBlocks = null;

    protected MessageMapperInterface $messageMapper;
    protected ToolMapperInterface $toolPayloadMapper;

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
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $this->key,
                'anthropic-version' => $version,
            ]);
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function systemPrompt(string|array|null $prompt): AIProviderInterface
    {
        if (is_array($prompt)) {
            $this->systemBlocks = array_map(function (SystemContent $block): array {
                $blockArray = ['text' => $block->content];
                if ($block->isCached()) {
                    $blockArray['cache_control'] = ['type' => 'ephemeral'];
                }
                return $blockArray;
            }, $prompt);
            $this->system = null;
            return $this;
        }

        $this->system = $prompt;
        $this->systemBlocks = null;
        return $this;
    }

    protected function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ?? $this->messageMapper = new MessageMapper();
    }

    protected function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolMapper();
    }

    /**
     * Build the request body for a chat ($stream = false) or streaming ($stream = true) request.
     *
     * Override to transform the body (e.g. Anthropic on Vertex moves the
     * model into the URL and the API version into the body).
     *
     * @param Message[] $messages
     * @return array<string, mixed>
     */
    protected function requestBody(array $messages, bool $stream = false): array
    {
        $json = [
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if ($stream) {
            $json['stream'] = true;
        }

        if ($this->system !== null) {
            $json['system'] = $this->system;
        } elseif ($this->systemBlocks !== null) {
            $json['system'] = $this->systemBlocks;
        }

        if ($this->tools !== []) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $json;
    }

    /**
     * Return the request URI for a chat ($stream = false) or streaming ($stream = true) request.
     *
     * Override to point at a different endpoint (e.g. Anthropic on Vertex).
     */
    protected function requestUri(bool $stream): string
    {
        return 'messages';
    }

    /**
     * @param string|ContentBlockInterface[]|null $content
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, string|array|null $content = null): ToolCallMessage
    {
        $tools = array_map(fn (array $tool): ToolInterface => $this->findTool($tool['name'])
            ->setInputs($tool['input'])
            ->setCallId($tool['id']), $toolCalls);

        return new ToolCallMessage($content, array_values($tools));
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
