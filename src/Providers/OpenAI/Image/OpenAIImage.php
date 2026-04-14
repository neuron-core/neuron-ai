<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Image;

use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\SSEParser;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\UniqueIdGenerator;

use function end;
use function is_string;

class OpenAIImage implements AIProviderInterface
{
    use HasHttpClient;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://api.openai.com/v1';

    /**
     * System instructions.
     */
    protected ?string $system = null;

    public function __construct(
        protected string $key,
        protected string $model,
        protected string $output_format = 'png',
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
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

    /**
     * https://developers.openai.com/api/reference/resources/images/methods/generate
     *
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        $message = end($messages);

        if ($this->system ?? false) {
            $message->addContent(new TextContent($this->system));
        }

        $body = [
            'model' => $this->model,
            'prompt' => $message->getContent(),
            'output_format' => $this->output_format,
            ...$this->parameters,
        ];

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'images/generations',
                body: $body
            )
        )->json();

        $result = new AssistantMessage(
            new ImageContent(
                $response['data'][0]['b64_json'],
                SourceType::BASE64,
                match($this->output_format) {
                    'png' => 'image/png',
                    'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    default => null
                }
            )
        );

        if ($response['usage']) {
            $result->setUsage(
                new Usage(
                    $response['usage']['input_tokens'],
                    $response['usage']['output_tokens']
                )
            );
        }

        return $result;
    }

    /**
     * https://developers.openai.com/api/docs/guides/image-generation?api=image#streaming
     *
     * @throws ProviderException
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        $message = end($messages);

        if ($this->system ?? false) {
            $message->addContent(new TextContent($this->system));
        }

        $body = [
            'stream' => true,
            'model' => $this->model,
            'prompt' => $message->getContent(),
            'output_format' => $this->output_format,
            ...$this->parameters,
        ];

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: 'images/generations',
                body: $body
            )
        );

        $messageId = UniqueIdGenerator::generateId('msg_');
        $content = null;
        $usage = new Usage(0, 0);

        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            $type = $line['type'] ?? null;

            if ($type === 'error') {
                throw new ProviderException(
                    $line['error']['message'] ?? 'Image generation failed.'
                );
            }

            if ($type === 'image_generation.partial_image') {
                $b64 = $line['b64_json'] ?? null;
                if (!is_string($b64) || $b64 === '') {
                    throw new ProviderException('Received a partial image event without b64_json payload.');
                }
                yield new ImageChunk($messageId, $b64);
            }

            if ($type === 'image_generation.completed') {
                $b64 = $line['b64_json'] ?? null;
                if (!is_string($b64) || $b64 === '') {
                    throw new ProviderException('Received a completed image event without b64_json payload.');
                }
                $content = $b64;

                if (isset($line['usage'])) {
                    $usage->inputTokens = $line['usage']['input_tokens'] ?? 0;
                    $usage->outputTokens = $line['usage']['output_tokens'] ?? 0;
                }
            }
        }

        if ($content === null) {
            throw new ProviderException('Image generation stream ended before a completed image was received.');
        }

        $result = new AssistantMessage(
            new ImageContent(
                $content,
                SourceType::BASE64,
                match($this->output_format) {
                    'png' => 'image/png',
                    'jpeg' => 'image/jpeg',
                    'webp' => 'image/webp',
                    default => null
                }
            )
        );

        $result->setUsage($usage);

        return $result;
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        throw new ProviderException('Structured output is not supported by OpenAI Text to Speech.');
    }

    public function messageMapper(): MessageMapperInterface
    {
        throw new ProviderException('Messages are not supported by OpenAI Text to Speech.');
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        throw new ProviderException('Tools are not supported by OpenAI Text to Speech.');
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }
}
