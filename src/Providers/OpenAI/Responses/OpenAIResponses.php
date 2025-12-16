<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Tools\ToolInterface;

use function array_map;
use function array_merge;
use function json_decode;
use function trim;
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
        // Use provided client or create default Guzzle client
        // Provider always configures authentication and base URI
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/') . '/')
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
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
        return $this->toolPayloadMapper ?? $this->toolPayloadMapper = new ToolMapper();
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
                    $citations = array_merge($citations, $this->extractCitations($content['text'], $content['annotations']));
                }
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
            fn (array $item): ToolInterface => $this->findTool($item['name'])
                ->setInputs(
                    json_decode((string) $item['arguments'], true)
                )
                ->setCallId($item['call_id']),
            $toolCalls
        );

        return new ToolCallMessage($content, $tools);
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
                    id: $fileCitation['file_id'] ?? uniqid('openai_responses_file_'),
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
                    id: $filePath['file_id'] ?? uniqid('openai_responses_path_'),
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
