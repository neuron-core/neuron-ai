<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ElevenLabs;

use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\UniqueIdGenerator;

use function base64_encode;
use function end;
use function trim;

class ElevenLabsTextToSpeech implements AIProviderInterface
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.elevenlabs.io/v1/text-to-speech';

    /**
     * System instructions.
     */
    protected ?string $system = null;

    public function __construct(
        protected string $key,
        protected string $model,
        protected string $voiceId,
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
    public function chat(Message ...$messages): Message
    {
        $message = end($messages);

        $json = [
            'model_id' => $this->model,
            'text' => $message->getContent(),
        ];

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: $this->voiceId,
                body: $json
            )
        );

        return new AssistantMessage(
            new AudioContent(base64_encode($response->body), SourceType::BASE64)
        );
    }

    /**
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        $message = end($messages);

        $json = [
            'model_id' => $this->model,
            'text' => $message->getContent(),
        ];

        $response = $this->httpClient->stream(
            HttpRequest::post(
                uri: $this->voiceId,
                body: $json
            )
        );

        $audio = '';
        $msgId = UniqueIdGenerator::generateId('msg_');

        while (! $response->eof()) {
            $chunk = $response->read(1024);
            yield new AudioChunk($msgId, $chunk);
            $audio .= $chunk;
        }

        return new AssistantMessage(
            new AudioContent(base64_encode($audio), SourceType::BASE64)
        );
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
