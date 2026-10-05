<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolCall;

use function array_flip;
use function array_diff_key;
use function array_keys;
use function array_reverse;
use function rtrim;
use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function mb_strlen;
use function uniqid;

class Anthropic implements AIProviderInterface
{
    use HasHttpClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    protected const MAX_CACHE_BREAKPOINTS = 4;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://api.anthropic.com/v1/';

    /**
     * System instructions.
     */
    protected ?SystemMessage $system = null;

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
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->httpHeaders = [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->key,
            'anthropic-version' => $version,
        ];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Headers of each chat and stream request.
     *
     * @return array<string, string>
     */
    protected function requestHeaders(): array
    {
        return $this->httpHeaders;
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->system = is_string($prompt) ? new SystemMessage($prompt) : $prompt;
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

        if ($this->system instanceof SystemMessage) {
            // Each block marked as cached becomes a prompt caching breakpoint.
            $json['system'] = array_map(function (TextContent $block): array {
                $mapped = ['type' => 'text', 'text' => $block->content];
                if ($block->isCached()) {
                    $mapped['cache_control'] = ['type' => 'ephemeral'];
                }
                return $mapped;
            }, array_values($this->system->getTextBlocks()));
        }

        if ($this->tools !== []) {
            $json['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $this->limitCacheBreakpoints($json);
    }

    /**
     * Anthropic refuses a request with more breakpoints than it allows, and the
     * markers stored with a conversation add up over a long thread. The ones on the
     * instructions and the tools keep their slot first, since that prefix outlives the
     * conversation; the rest go to the most recent ones of the conversation.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function limitCacheBreakpoints(array $body): array
    {
        // A top-level cache_control is one more breakpoint, placed by Anthropic
        $slots = self::MAX_CACHE_BREAKPOINTS - (isset($body['cache_control']) ? 1 : 0);

        foreach (['system', 'tools', 'messages'] as $part) {
            if (is_array($body[$part] ?? null)) {
                $body[$part] = $this->keepLatestBreakpoints($body[$part], $slots);
            }
        }

        return $body;
    }

    /**
     * Walks the entries from the last one and removes the breakpoints left without
     * a slot. Only block lists are entered, through their "content" key: a tool
     * schema or a tool call may well have an argument named cache_control.
     *
     * @param array<int|string, mixed> $entries
     * @return array<int|string, mixed>
     */
    protected function keepLatestBreakpoints(array $entries, int &$slots): array
    {
        foreach (array_reverse(array_keys($entries)) as $key) {
            if (!is_array($entries[$key])) {
                continue;
            }

            if (isset($entries[$key]['cache_control'])) {
                if ($slots > 0) {
                    $slots--;
                } else {
                    unset($entries[$key]['cache_control']);
                }
            }

            if (is_array($entries[$key]['content'] ?? null)) {
                $entries[$key]['content'] = $this->keepLatestBreakpoints($entries[$key]['content'], $slots);
            }
        }

        return $entries;
    }

    /**
     * Return the request URI for a chat ($stream = false) or streaming ($stream = true) request.
     *
     * Override to point at a different endpoint (e.g. Anthropic on Vertex).
     */
    protected function requestUri(bool $stream): string
    {
        return rtrim($this->baseUri, '/') . '/messages';
    }

    /**
     * @param string|ContentBlockInterface[]|null $content
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, string|array|null $content = null): ToolCallMessage
    {
        $tools = array_map(
            fn (array $tool): ToolCall => $this->newToolCall(
                $tool['name'],
                $tool['id'],
                $this->decodeToolArguments($tool['name'], $tool['input'] ?? null),
            ),
            $toolCalls
        );

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

                // A citation supports its whole text block; where it points in the
                // source (characters, pages, blocks) stays in the metadata
                if (isset($block['citations']) && is_array($block['citations'])) {
                    foreach ($block['citations'] as $citation) {
                        $citations[] = new Citation(
                            id: uniqid('anthropic_'),
                            source: $citation['url'] ?? $citation['source'] ?? '',
                            title: $citation['title'] ?? $citation['document_title'] ?? null,
                            startIndex: $textOffset,
                            endIndex: $textOffset + $textLength,
                            citedText: $citation['cited_text'] ?? null,
                            metadata: [
                                ...array_diff_key($citation, array_flip(['url', 'source', 'title', 'document_title', 'cited_text'])),
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
