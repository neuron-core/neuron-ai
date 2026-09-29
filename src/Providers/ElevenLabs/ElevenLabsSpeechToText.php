<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ElevenLabs;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\Providers\UploadsAudioFile;

use function end;

class ElevenLabsSpeechToText implements AIProviderInterface
{
    use HasHttpClient;
    use UploadsAudioFile;

    protected string $baseUri = 'https://api.elevenlabs.io/v1/speech-to-text';

    /**
     * System instructions.
     */
    protected ?string $system = null;

    /**
     * @param array<string, mixed> $parameters Extra request fields. A file upload sends them as
     *        multipart form fields, where every value becomes a string: pass 'true', not true.
     */
    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->httpHeaders = [
            'Accept' => 'application/json',
            'xi-api-key' => $this->key,
        ];
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

    /**
     * @throws HttpException
     * @throws ProviderException
     */
    public function chat(Message ...$messages): ProviderResponse
    {
        $file = $this->audioFilePart(end($messages)->getAudio());

        $body = [
            'file' => $file,
            'model_id' => $this->model,
            ...$this->parameters,
        ];

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: $this->baseUri,
                body: $body,
                headers: $this->httpHeaders,
            )
        )->json();

        return new ProviderResponse(
            message: new AssistantMessage($response['text'])
        );
    }

    /**
     * @throws ProviderException
     */
    public function stream(Message ...$messages): Generator
    {
        throw new ProviderException('Streaming is not supported by ElevenLabs Speech to Text.');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        throw new ProviderException('Structured output is not supported by ElevenLabs Speech to Text.');
    }

    protected function messageMapper(): MessageMapperInterface
    {
        throw new ProviderException('Messages are not supported by ElevenLabs Speech to Text.');
    }

    protected function toolPayloadMapper(): ToolMapperInterface
    {
        throw new ProviderException('Tools are not supported by ElevenLabs Speech to Text.');
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }
}
