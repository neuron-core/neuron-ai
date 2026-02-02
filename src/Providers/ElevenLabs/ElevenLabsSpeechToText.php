<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ElevenLabs;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

use function end;
use function fopen;
use function is_array;
use function trim;

class ElevenLabsSpeechToText implements AIProviderInterface
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.elevenlabs.io/v1/speech-to-text';

    /**
     * System instructions.
     */
    protected ?string $system = null;

    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/').'/')
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'xi-api-key' => $this->key,
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
        ];

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'audio/transcriptions',
                body: $body
            )
        )->json();

        return new AssistantMessage($response['text']);
    }

    /**
     * @throws ProviderException
     */
    public function stream(Message|array $messages): Generator
    {
        throw new ProviderException('Streaming is not supported by OpenAI Text to Speech.');
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
