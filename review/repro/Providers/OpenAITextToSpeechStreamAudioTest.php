<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Audio\OpenAITextToSpeech;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function base64_encode;

class OpenAITextToSpeechStreamAudioTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_streamed_audio_deltas_decode_to_the_full_audio(): void
    {
        $body = self::sseBody([
            ['type' => 'speech.audio.delta', 'audio' => base64_encode('A')],
            ['type' => 'speech.audio.delta', 'audio' => base64_encode('BC')],
            ['type' => 'speech.audio.done', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]],
        ]);
        $provider = new OpenAITextToSpeech('key', 'gpt-4o-mini-tts', 'alloy', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame('ABC', base64_decode($message->getAudio()->content, true));
    }
}
