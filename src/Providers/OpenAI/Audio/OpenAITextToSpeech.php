<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Audio;

use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\AssistantMessage;
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

use function base64_encode;
use function end;
use function is_array;
use function trim;

class OpenAITextToSpeech implements AIProviderInterface
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
        protected string $voice,
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
     * https://platform.openai.com/docs/api-reference/audio/createSpeech
     *
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        $message = is_array($messages) ? end($messages) : $messages;

        $json = [
            'model' => $this->model,
            'input' => $message->getContent(),
            'voice' => $this->voice,
            'instructions' => $this->system ?? '',
            ...$this->parameters
        ];

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'audio/speech',
                body: $json
            )
        );

        return new AssistantMessage(
            new AudioContent(base64_encode($response->body), SourceType::BASE64)
        );
    }
    /**
     * https://platform.openai.com/docs/api-reference/audio/speech-audio-delta-event
     *
     * @throws HttpException
     * @throws ProviderException
     */
    public function stream(Message ...$messages): Generator
    {
        $message = is_array($messages) ? end($messages) : $messages;

        $json = [
            'stream' => true,
            'model' => $this->model,
            'input' => $message->getContent(),
            'voice' => $this->voice,
            'instructions' => $this->system ?? '',
            ...$this->parameters
        ];

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: 'audio/speech',
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

            // Delta
            if ($line['type'] === 'speech.audio.delta') {
                $content .= $line['audio'];

                yield new AudioChunk($msgId, $line['audio']);
            }

            // Done
            if ($line['type'] === 'speech.audio.done') {
                $usage->inputTokens = $line['usage']['input_tokens'] ?? 0;
                $usage->outputTokens = $line['usage']['output_tokens'] ?? 0;
            }
        }

        $message = new AssistantMessage(
            new AudioContent($content, SourceType::BASE64)
        );
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
