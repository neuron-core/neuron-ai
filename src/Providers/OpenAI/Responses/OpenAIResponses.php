<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use GuzzleHttp\Client;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\HasGuzzleClient;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolPayloadMapperInterface;
use NeuronAI\Tools\ToolInterface;

class OpenAIResponses implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleResponses;
    use HandleResponsesStream;
    use HandleResponsesStructured;

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
            'base_uri' => \trim($this->baseUri, '/').'/',
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
        return $this->messageMapper ?? $this->messageMapper = new MessageMapperResponses();
    }

    public function toolPayloadMapper(): ToolPayloadMapperInterface
    {
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolPayloadMapperResponses();
    }

    protected function createAssistantMessage(array $response): AssistantMessage
    {
        $blocks = [];
        $citations = [];
        foreach ($response['output'] as $block) {
            if ($block['type'] === 'message') {
                $content = $block['content'][0];

                $blocks[] = new TextContent($content['text']);

                if (isset($content['annotations'])) {
                    $citations = \array_merge($citations, $this->extractCitations($content['text'], $content['annotations']));
                }
            }

            if ($block['type'] === 'reasoning') {
                $blocks[] = new ReasoningContent($block['summary'][0]['text'], $block['summary'][0]['id']);
            }
        }

        $message = new AssistantMessage($blocks);

        if ($citations !== []) {
            $message->addMetadata('citations', $citations);
        }

        if (\array_key_exists('usage', $response)) {
            $message->setUsage(
                new Usage($response['usage']['input_tokens'], $response['usage']['output_tokens'])
            );
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $toolCalls
     * @throws ProviderException
     */
    protected function createToolCallMessage(array $toolCalls, ?string $text = null, ?array $usage = null): ToolCallMessage
    {
        $tools = \array_map(
            fn (array $item): ToolInterface => $this->findTool($item['name'])
                ->setInputs(
                    \json_decode((string) $item['arguments'], true)
                )
                ->setCallId($item['call_id']),
            $toolCalls
        );

        $message = new ToolCallMessage($text, $tools);

        if (!\is_null($usage)) {
            $message->setUsage(
                new Usage($usage['input_tokens'], $usage['output_tokens'])
            );
        }

        return $message;
    }

    /**
     * Extract citations from OpenAI Responses annotations.
     *
     * @param array<int, array<string, mixed>> $annotations
     * @return Citation[]
     */
    protected function extractCitations(string $text, array $annotations): array
    {
        $citations = [];

        foreach ($annotations as $annotation) {
            $type = $annotation['type'] ?? null;

            if ($type === 'file_citation') {
                $fileCitation = $annotation['file_citation'] ?? [];
                $citations[] = new Citation(
                    id: $fileCitation['file_id'] ?? \uniqid('openai_responses_file_'),
                    source: $fileCitation['file_id'] ?? '',
                    startIndex: $annotation['start_index'] ?? null,
                    endIndex: $annotation['end_index'] ?? null,
                    citedText: $annotation['text'] ?? null,
                    metadata: [
                        'type' => 'file_citation',
                        'quote' => $fileCitation['quote'] ?? null,
                        'provider' => 'openai_responses',
                    ]
                );
            } elseif ($type === 'file_path') {
                $filePath = $annotation['file_path'] ?? [];
                $citations[] = new Citation(
                    id: $filePath['file_id'] ?? \uniqid('openai_responses_path_'),
                    source: $filePath['file_id'] ?? '',
                    startIndex: $annotation['start_index'] ?? null,
                    endIndex: $annotation['end_index'] ?? null,
                    citedText: $annotation['text'] ?? null,
                    metadata: [
                        'type' => 'file_path',
                        'provider' => 'openai_responses',
                    ]
                );
            }
        }

        return $citations;
    }
}
