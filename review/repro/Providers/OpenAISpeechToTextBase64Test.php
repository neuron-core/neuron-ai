<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function base64_encode;

class OpenAISpeechToTextBase64Test extends TestCase
{
    use RecordsHttpRequests;

    public function test_base64_audio_is_uploaded_decoded_as_the_multipart_file_part(): void
    {
        $provider = new OpenAISpeechToText('key', 'whisper-1', httpClient: $this->recordingClient(new Response(200, body: '{"text":"Hi"}')));
        $message = (new UserMessage('Transcribe'))->addContent(new AudioContent(base64_encode('RIFF-fake-wav'), SourceType::BASE64, 'audio/wav'));

        $provider->chat($message);

        $request = $this->sentRequests[0]['request'];
        $this->assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $this->assertMatchesRegularExpression('/name="file"; filename="[^"]+\.wav"\r\n(?:[^\r\n]+\r\n)*\r\nRIFF-fake-wav\r\n/', (string) $request->getBody());
    }
}
