<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Audio;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
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
use function fopen;
use function is_array;
use function trim;

class OpeAISpeechToText implements AIProviderInterface
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
        protected string $language = 'en',
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null
    ) {
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

    /**
     * @throws HttpException
     */
    public function chat(Message|array $messages): Message
    {
        $message = is_array($messages) ? end($messages) : $messages;

        $body = [
            'file' => fopen($message->getAudio(), 'r'),
            'model' => $this->model,
            'language' => $this->language,
            'response_format' => 'json',
        ];

        if ($this->system !== null && $this->system !== '') {
            $body['prompt'] = $this->system;
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'audio/transcriptions',
                body: $body
            )
        )->json();

        $message = new AssistantMessage($response['text']);
        $message->setUsage(
            new Usage(
                $response['usage']['input_tokens'],
                $response['usage']['output_tokens']
            )
        );
        return $message;
    }

    /**
     * @throws HttpException
     * @throws ProviderException
     */
    public function stream(Message|array $messages): Generator
    {
        $message = is_array($messages) ? end($messages) : $messages;

        $json = [
            'stream' => true,
            'file' => fopen($message->getAudio(), 'r'),
            'model' => $this->model,
            'language' => $this->language,
            'response_format' => 'json',
        ];

        if ($this->system !== null && $this->system !== '') {
            $json['prompt'] = $this->system;
        }

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: 'audio/transcriptions',
                body: $json
            )
        );

        $content = '';
        $usage = new Usage(0, 0);
        $msgId = UniqueIdGenerator::generateId('msg_');

        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            if ($line['type'] === 'transcript.text.delta') {
                $content .= $line['delta'];
                yield new TextChunk($msgId, $line['delta']);
            }

            if ($line['type'] === 'transcript.text.done') {
                $usage->inputTokens = $line['usage']['input_tokens'] ?? 0;
                $usage->outputTokens = $line['usage']['output_tokens'] ?? 0;
            }
        }

        $message = new AssistantMessage($content);
        $message->setUsage($usage);
        return $message;
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
