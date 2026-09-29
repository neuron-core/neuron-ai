<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ElevenLabs;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ElevenLabs\ElevenLabsSpeechToText;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function file_put_contents;
use function iterator_to_array;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class ElevenLabsSpeechToTextTest extends TestCase
{
    use RecordsHttpRequests;

    protected const SECRET = 'xi-SECRET-0123456789';
    protected const AUDIO = "RIFF\x24\x00\x00\x00WAVEfmt \x00\xff";

    protected string $audioFile;

    protected function setUp(): void
    {
        $this->audioFile = tempnam(sys_get_temp_dir(), 'neuron_stt_');
        file_put_contents($this->audioFile, self::AUDIO);
    }

    protected function tearDown(): void
    {
        @unlink($this->audioFile);
    }

    protected function provider(Response $response): ElevenLabsSpeechToText
    {
        return new ElevenLabsSpeechToText(self::SECRET, 'scribe_v1', httpClient: $this->recordingClient($response));
    }

    protected function recording(): UserMessage
    {
        return new UserMessage(new AudioContent($this->audioFile, SourceType::URL, 'audio/wav'));
    }

    public function test_transcript_text_becomes_the_assistant_message(): void
    {
        $message = $this->provider(new Response(200, body: '{"language_code":"it","text":"Ciao a tutti"}'))
            ->chat(new UserMessage('ignored'), $this->recording())
            ->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Ciao a tutti', $message->getContent());
    }

    public function test_audio_file_and_model_id_are_uploaded_as_multipart_to_the_documented_endpoint(): void
    {
        $this->provider(new Response(200, body: '{"text":"ok"}'))->chat($this->recording());

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.elevenlabs.io/v1/speech-to-text'], $this->sentTargets());
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        $this->assertSame(self::SECRET, $request->getHeaderLine('xi-api-key'));
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getUri());
        $this->assertStringContainsString("\r\n\r\n".self::AUDIO."\r\n", (string) $request->getBody());
        $this->assertStringContainsString('name="model_id"', (string) $request->getBody());
        $this->assertStringContainsString("\r\n\r\nscribe_v1\r\n", (string) $request->getBody());
    }

    public function test_audio_that_is_not_a_file_path_is_refused_before_any_request(): void
    {
        $provider = $this->provider(new Response(200, body: '{"text":"ok"}'));

        try {
            $provider->chat(new UserMessage(new AudioContent(base64_encode(self::AUDIO), SourceType::BASE64, 'audio/wav')));
            $this->fail('Base64 audio must be refused.');
        } catch (ProviderException $exception) {
            $this->assertStringContainsString('SourceType::URL', $exception->getMessage());
        }

        $this->assertSame([], $this->sentRequests);
    }

    public function test_http_error_does_not_expose_the_api_key(): void
    {
        $provider = $this->provider(new Response(401, body: '{"detail":{"status":"invalid_api_key"}}'));

        try {
            $provider->chat($this->recording());
            $this->fail('A 401 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function test_streaming_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Streaming is not supported by ElevenLabs Speech to Text.');

        iterator_to_array($this->provider(new Response(200))->stream($this->recording()));
    }

    public function test_structured_output_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Structured output is not supported by ElevenLabs Speech to Text.');

        $this->provider(new Response(200))->structured($this->recording(), 'Transcript', []);
    }

    public function test_configured_parameters_are_uploaded_as_form_fields(): void
    {
        $provider = new ElevenLabsSpeechToText(self::SECRET, 'scribe_v1', ['language_code' => 'it', 'diarize' => 'true'], $this->recordingClient(new Response(200, body: '{"text":"ok"}')));

        $provider->chat($this->recording());

        $body = (string) $this->sentRequests[0]['request']->getBody();
        $this->assertMatchesRegularExpression('/name="language_code"\r\n(?:[^\r\n]+\r\n)*\r\nit\r\n/', $body);
        $this->assertMatchesRegularExpression('/name="diarize"\r\n(?:[^\r\n]+\r\n)*\r\ntrue\r\n/', $body);
    }
}
