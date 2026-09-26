<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ElevenLabs;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ElevenLabs\ElevenLabsTextToSpeech;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function json_decode;

/**
 * https://elevenlabs.io/docs/api-reference/text-to-speech/convert
 */
class ElevenLabsTextToSpeechRequestTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_text_to_speech_forwards_configured_parameters(): void
    {
        $provider = new ElevenLabsTextToSpeech('key', 'eleven_v3', 'voice', ['voice_settings' => ['stability' => 0.5]], $this->recordingClient(new Response(200, body: 'mp3')));

        $provider->chat(new UserMessage('Hi'));

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
        $this->assertSame(['model_id' => 'eleven_v3', 'text' => 'Hi', 'voice_settings' => ['stability' => 0.5]], $body);
    }

    public function test_text_to_speech_stream_forwards_configured_parameters(): void
    {
        $provider = new ElevenLabsTextToSpeech('key', 'eleven_v3', 'voice', ['voice_settings' => ['stability' => 0.5]], $this->recordingClient(new Response(200, body: 'mp3')));

        $stream = $provider->stream(new UserMessage('Hi'));
        foreach ($stream as $chunk) {
        }

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
        $this->assertSame(['stability' => 0.5], $body['voice_settings'] ?? null);
    }

    public function test_text_to_speech_voice_id_cannot_escape_the_voice_path(): void
    {
        $provider = new ElevenLabsTextToSpeech('key', 'eleven_v3', '../../user?x=', httpClient: $this->recordingClient(new Response(200, body: 'mp3')));

        $provider->chat(new UserMessage('Hi'));

        $uri = $this->sentRequests[0]['request']->getUri();
        $this->assertSame('/v1/text-to-speech/..%2F..%2Fuser%3Fx%3D', $uri->getPath());
        $this->assertSame('', $uri->getQuery());
    }
}
