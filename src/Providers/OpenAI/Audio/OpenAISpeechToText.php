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
use NeuronAI\HttpClient\HttpMethod;
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

class OpenAISpeechToText implements AIProviderInterface
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

        $fields = [
            'model' => $this->model,
            'language' => $this->language,
            'response_format' => 'json',
        ];

        if ($this->system !== null && $this->system !== '') {
            $field['prompt'] = $this->system;
        }

        $boundary = '----NeuronAIBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($fields, $boundary, [
            'field'    => 'file',
            'filename' => $message->getAudio(),
            'mime'     => 'application/octet-stream',
            'content'  => file_get_contents($message->getAudio()),
        ]);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Content-Length' => strlen($body),
        ];

        $response = $this->httpClient->request(
            new HttpRequest(
                method: HttpMethod::POST,
                uri: 'audio/transcriptions',
                headers: $headers,
                body: $body,
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

        $fields = [
            'stream' => true,
            'model' => $this->model,
            'language' => $this->language,
            'response_format' => 'json',
        ];

        if ($this->system !== null && $this->system !== '') {
            $field['prompt'] = $this->system;
        }

        $boundary = '----NeuronAIBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($fields, $boundary, [
            'field'    => 'file',
            'filename' => $message->getAudio(),
            'mime'     => 'application/octet-stream',
            'content'  => file_get_contents($message->getAudio()),
        ]);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Content-Length' => strlen($body),
        ];

        $stream = $this->httpClient->stream(
            new HttpRequest(
                method: HttpMethod::POST,
                uri: 'audio/transcriptions',
                headers: $headers,
                body: $body,
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

    /**
     * Builds a multipart body string for use in HTTP requests with multipart/form-data encoding.
     *
     * @param array $fields An associative array of form fields where keys are field names
     *                      and values are field values. Supports nested arrays for multiple values of the same field.
     * @param string $boundary The boundary string used to separate parts in the multipart body.
     * @param array $file An associative array containing file data to include in the multipart body:
     *                    - 'field': The name of the form field for the file (default: 'file').
     *                    - 'filename': The name of the uploaded file (default: 'upload.mp3').
     *                    - 'mime': The MIME type of the file (default: 'application/octet-stream').
     *                    - 'content': The binary content of the file.
     * @return string The generated multipart body as a string.
     */
    private function buildMultipartBody(array $fields, string $boundary, array $file): string
    {
        $eol = "\r\n";
        $body = '';
        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $body .= "--{$boundary}{$eol}";
                    $body .= 'Content-Disposition: form-data; name="' . $name . '[]"'.$eol.$eol;
                    $body .= (string)$v . $eol;
                }
                continue;
            }
            $body .= "--{$boundary}{$eol}";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"'.$eol.$eol;
            $body .= (string)$value . $eol;
        }
        $fileField = $file['field'] ?? 'file';
        $filename  = $file['filename'] ?? 'upload.mp3';
        $mime      = $file['mime'] ?? 'application/octet-stream';
        $content   = $file['content'] ?? '';
        $body .= "--{$boundary}{$eol}";
        $body .= 'Content-Disposition: form-data; name="' . $fileField . '"; filename="' . $filename . '"' . $eol;
        $body .= 'Content-Type: ' . $mime . $eol . $eol;
        $body .= $content . $eol;
        $body .= "--{$boundary}--{$eol}";
        return $body;
    }
}
