<?php

namespace NeuronAI\Providers\ZAI\Audio;

use Generator;
use LibDNS\Records\Resource;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
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
use Rector\StaticTypeMapper\Mapper\ScalarStringToTypeMapper;

class ZAITranscription implements AIProviderInterface
{
    use HasHttpClient;

    /**
     * The main URL of the provider API.
     */
    protected string $baseUri = 'https://api.z.ai/api/paas/v4';

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
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
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
    public function chat(Message ...$messages): Message
    {
        $message = end($messages);

        $body = [
            'model' => $this->model,
        ];

        $this->addFile($body, $message->getAudio());

        if ($message->getContent() !== null) {
            $body['prompt'] = $message->getContent();
        }

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
     * @throws HttpException
     */
    public function stream(Message ...$messages): Generator
    {
        $message = end($messages);

        $body = [
            'stream' => true,
            'model' => $this->model,
        ];

        $this->addFile($body, $message->getAudio());

        if ($message->getContent() !== null) {
            $body['prompt'] = $message->getContent();
        }

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: 'audio/transcriptions',
                body: $body
            )
        );

        $msgId = UniqueIdGenerator::generateId('msg_');

        echo "\n";
        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            yield new AudioChunk($msgId, '');

            echo "\n".json_encode($line);
        }
        echo "\n\n";

        return new AssistantMessage('');
    }

    protected function addFile(array &$body, AudioContent $audio): void
    {
        if ($audio->sourceType === SourceType::BASE64) {
            $body['file_base64'] = 'data:'.$audio->mediaType.';base64,'.$audio->content;
        } elseif($audio->sourceType === SourceType::URL) {
            $body['file'] = fopen($audio->content, 'r');
        } else {
            throw new ProviderException("Source type not supported: {$audio->sourceType->value}");
        }
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
