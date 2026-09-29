<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
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

use function in_array;
use function array_map;
use function array_merge;
use function array_unshift;
use function array_values;
use function is_array;
use function is_string;
use function uniqid;

class OpenAIResponses implements AIProviderInterface
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
    protected ?SystemMessage $system = null;

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
        // Use the provided client or create the default Guzzle client
        // Provider always configures authentication and base URI
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->httpHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->key,
        ];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->system = is_string($prompt) ? new SystemMessage($prompt) : $prompt;
        return $this;
    }

    /**
     * @param Message[] $messages
     * @return array<string, mixed>
     */
    protected function requestBody(array $messages, bool $stream = false): array
    {
        $body = $stream ? ['stream' => true] : [];
        $body = [
            ...$body,
            'model' => $this->model,
            'input' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        if ($this->system instanceof SystemMessage) {
            $this->attachSystemPrompt($body, $this->system);
        }

        if ($this->tools !== []) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);
        }

        return $body;
    }

    /**
     * A generated image, or a streamed preview of it: base64 in the requested format.
     */
    protected function createImageContent(string $base64, ?string $format): ImageContent
    {
        return new ImageContent($base64, SourceType::BASE64, 'image/' . ($format ?? 'png'));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function attachSystemPrompt(array &$body, SystemMessage $system): void
    {
        $blocks = $system->getTextBlocks();
        $hasCacheBreakpoint = false;

        foreach ($blocks as $block) {
            if ($block instanceof SystemContent && $block->isCached()) {
                $hasCacheBreakpoint = true;
                break;
            }
        }

        if (!$hasCacheBreakpoint) {
            $body['instructions'] = $system->getContent();
            return;
        }

        unset($body['instructions']);

        $content = array_map(function (TextContent $block): array {
            $mapped = [
                'type' => 'input_text',
                'text' => $block->content,
            ];

            if ($block instanceof SystemContent && $block->isCached()) {
                $mapped['prompt_cache_breakpoint'] = ['mode' => 'explicit'];
            }

            return $mapped;
        }, array_values($blocks));

        $input = is_array($body['input'] ?? null) ? $body['input'] : [];
        array_unshift($input, [
            'role' => 'developer',
            'content' => $content,
        ]);
        $body['input'] = $input;

        $promptCacheOptions = is_array($body['prompt_cache_options'] ?? null)
            ? $body['prompt_cache_options']
            : [];
        $body['prompt_cache_options'] = [
            ...$promptCacheOptions,
            'mode' => 'explicit',
        ];
    }

    protected function messageMapper(): MessageMapperInterface
    {
        return $this->messageMapper ??= new MessageMapper();
    }

    protected function toolPayloadMapper(): ToolMapperInterface
    {
        return $this->toolPayloadMapper ??= new ToolMapper();
    }

    protected function createAssistantMessage(array $response): AssistantMessage
    {
        $blocks = [];
        $citations = [];
        foreach ($response['output'] as $block) {
            // A message holds several parts; a refusal stays readable as text
            if ($block['type'] === 'message') {
                foreach ($block['content'] ?? [] as $part) {
                    if (($part['type'] ?? null) === 'refusal') {
                        $blocks[] = new TextContent($part['refusal'] ?? '');
                    } elseif (isset($part['text'])) {
                        $blocks[] = new TextContent($part['text']);
                        $citations = array_merge($citations, $this->extractCitations($part['annotations'] ?? []));
                    }
                }
            }

            if ($block['type'] === 'image_generation_call' && isset($block['result'])) {
                $blocks[] = $this->createImageContent($block['result'], $block['output_format'] ?? null);
            }

            if ($block['type'] === 'reasoning' && !empty($block['summary'])) {
                $blocks[] = new ReasoningContent($block['summary'][0]['text'], $block['id']);
            }
        }

        $message = new AssistantMessage($blocks);

        if ($citations !== []) {
            $message->addMetadata('citations', $citations);
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $toolCalls
     * @param ContentBlockInterface[]|null $content
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, array|null $content = null): ToolCallMessage
    {
        $tools = array_map(
            fn (array $item): ToolCall => $this->newToolCall(
                $item['name'],
                $item['call_id'],
                $this->decodeToolArguments($item['name'], $item['arguments'] ?? null),
            ),
            $toolCalls
        );

        return new ToolCallMessage($content, array_values($tools));
    }

    /**
     * Responses annotations are flat. A file annotation marks a single
     * position of the answer, so its span starts and ends there.
     *
     * @param array<int, array<string, mixed>> $annotations
     * @return Citation[]
     */
    protected function extractCitations(array $annotations): array
    {
        $citations = [];

        foreach ($annotations as $annotation) {
            $type = $annotation['type'] ?? null;

            if (!in_array($type, ['url_citation', 'file_citation', 'container_file_citation', 'file_path'], true)) {
                continue;
            }

            $metadata = ['type' => $type, 'provider' => 'openai_responses'];
            if (isset($annotation['container_id'])) {
                $metadata['container_id'] = $annotation['container_id'];
            }

            $citations[] = new Citation(
                id: $annotation['file_id'] ?? uniqid('openai_responses_'),
                source: $annotation['url'] ?? $annotation['file_id'] ?? '',
                title: $annotation['title'] ?? $annotation['filename'] ?? null,
                startIndex: $annotation['start_index'] ?? $annotation['index'] ?? null,
                endIndex: $annotation['end_index'] ?? $annotation['index'] ?? null,
                metadata: $metadata
            );
        }

        return $citations;
    }
}
