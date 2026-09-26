<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ElevenLabs;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ElevenLabs\ElevenLabsSpeechToText;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * https://elevenlabs.io/docs/api-reference/speech-to-text/convert
 */
class ElevenLabsSpeechToTextContractTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_speech_to_text_posts_multipart_model_id_to_the_documented_endpoint(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'neuron_stt_');
        file_put_contents($file, 'RIFF');

        try {
            (new ElevenLabsSpeechToText('key', 'scribe_v1', httpClient: $this->recordingClient(new Response(200, body: '{"text":"ok"}'))))
                ->chat(new UserMessage(new AudioContent($file, SourceType::URL)));
        } finally {
            unlink($file);
        }

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.elevenlabs.io/v1/speech-to-text'], $this->sentTargets());
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('name="model_id"', (string) $request->getBody());
    }

    public function test_speech_to_text_rejects_audio_it_cannot_upload(): void
    {
        $provider = new ElevenLabsSpeechToText('key', 'scribe_v1', httpClient: $this->recordingClient(new Response(200, body: '{"text":"ok"}')));

        $this->expectException(ProviderException::class);
        $provider->chat(new UserMessage(new AudioContent(base64_encode('RIFF'), SourceType::BASE64, 'audio/wav')));
    }
}
