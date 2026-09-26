<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ElevenLabs;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ElevenLabs\ElevenLabsTextToSpeech;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function count;
use function implode;
use function iterator_to_array;
use function json_decode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

class ElevenLabsTextToSpeechTest extends TestCase
{
    use RecordsHttpRequests;
    use ConsumesProviderStreams;

    protected const SECRET = 'xi-SECRET-0123456789';

    /** Binary audio with NUL bytes, invalid UTF-8 sequences and whitespace bytes at both ends. */
    protected const AUDIO = "\x00\x20ID3\x04\x00\x00\xff\xfb\x90\x64\x00\xc3\x28\r\n\x00";

    protected function provider(Response $response): ElevenLabsTextToSpeech
    {
        return new ElevenLabsTextToSpeech(self::SECRET, 'eleven_multilingual_v2', 'voice-123', httpClient: $this->recordingClient($response));
    }

    public function test_chat_posts_the_text_to_the_voice_endpoint_with_the_api_key_header(): void
    {
        $provider = $this->provider(new Response(200, ['Content-Type' => 'audio/mpeg'], self::AUDIO));
        $provider->systemPrompt('ignored by speech synthesis');

        $provider->chat(new UserMessage('Earlier turn'), new UserMessage('Ciao, come stai?'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.elevenlabs.io/v1/text-to-speech/voice-123'], $this->sentTargets());
        $this->assertSame(self::SECRET, $request->getHeaderLine('xi-api-key'));
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getUri());
        $this->assertSame(
            ['model_id' => 'eleven_multilingual_v2', 'text' => 'Ciao, come stai?'],
            json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_chat_returns_the_binary_audio_as_lossless_base64(): void
    {
        $message = $this->provider(new Response(200, body: self::AUDIO))->chat(new UserMessage('Hi'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $audio = $message->getAudio();
        $this->assertSame(SourceType::BASE64, $audio->sourceType);
        $this->assertSame(self::AUDIO, base64_decode($audio->content, true));
    }

    public function test_stream_yields_raw_audio_chunks_and_returns_the_whole_audio(): void
    {
        $body = str_repeat(self::AUDIO, 200);
        $provider = $this->provider(new Response(200, body: $body));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $contents = $this->contentsOf(AudioChunk::class, $chunks);
        $this->assertGreaterThan(1, count($contents));
        $this->assertSame($body, implode('', $contents));
        $this->assertSame($body, base64_decode($message->getAudio()->content, true));
        foreach ($chunks as $chunk) {
            $this->assertSame($message->getId(), $chunk->messageId);
        }
    }

    public function test_http_error_does_not_expose_the_api_key(): void
    {
        $provider = $this->provider(new Response(401, body: '{"detail":{"status":"invalid_api_key"}}'));

        try {
            $provider->chat(new UserMessage('Hi'));
            $this->fail('A 401 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringContainsString('invalid_api_key', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function test_structured_output_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Structured output is not supported');

        $this->provider(new Response(200))->structured(new UserMessage('Hi'), 'Person', []);
    }

    public function test_tools_are_ignored(): void
    {
        $provider = $this->provider(new Response(200, body: self::AUDIO));

        $this->assertSame($provider, $provider->setTools([new ToolStub('lookup')]));
        iterator_to_array($provider->stream(new UserMessage('Hi')));
        $this->assertArrayNotHasKey('tools', json_decode((string) $this->sentRequests[0]['request']->getBody(), true));
    }
}
