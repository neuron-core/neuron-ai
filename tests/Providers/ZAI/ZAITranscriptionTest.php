<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ZAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ZAI\Audio\ZAITranscription;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

class ZAITranscriptionTest extends TestCase
{
    use RecordsHttpRequests;
    use ConsumesProviderStreams;

    protected const SECRET = 'zai-SECRET-0123456789';

    protected function provider(Response $response): ZAITranscription
    {
        return new ZAITranscription(self::SECRET, 'glm-asr', httpClient: $this->recordingClient($response));
    }

    public function test_base64_audio_is_sent_as_a_data_uri_with_the_prompt(): void
    {
        $message = new UserMessage([
            new TextContent('Names: Neuron, Inspector'),
            new AudioContent('UklGRg==', SourceType::BASE64, 'audio/wav'),
        ]);

        $answer = $this->provider(new Response(200, body: '{"text":"Hello Neuron","usage":{"prompt_tokens":30,"completion_tokens":4}}'))
            ->chat($message)->message();

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.z.ai/api/paas/v4/audio/transcriptions'], $this->sentTargets());
        $this->assertSame('Bearer '.self::SECRET, $request->getHeaderLine('Authorization'));
        $this->assertSame([
            'model' => 'glm-asr',
            'file_base64' => 'data:audio/wav;base64,UklGRg==',
            'prompt' => 'Names: Neuron, Inspector',
        ], json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('Hello Neuron', $answer->getContent());
        $this->assertSame([30, 4], [$answer->getUsage()->inputTokens, $answer->getUsage()->outputTokens]);
    }

    public function test_audio_without_prompt_sends_no_prompt_field(): void
    {
        $this->provider(new Response(200, body: '{"text":"ok"}'))
            ->chat(new UserMessage(new AudioContent('UklGRg==', SourceType::BASE64, 'audio/wav')));

        $this->assertArrayNotHasKey('prompt', json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_local_audio_file_is_uploaded_as_multipart(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'neuron_asr_');
        file_put_contents($file, "RIFF\x00\xffWAVE");

        try {
            $this->provider(new Response(200, body: '{"text":"ok"}'))
                ->chat(new UserMessage(new AudioContent($file, SourceType::URL, 'audio/wav')));
        } finally {
            unlink($file);
        }

        $request = $this->sentRequests[0]['request'];
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        $this->assertStringContainsString("\r\n\r\nRIFF\x00\xffWAVE\r\n", $body);
        $this->assertStringContainsString('name="model"', $body);
        $this->assertStringContainsString("\r\n\r\nglm-asr\r\n", $body);
    }

    public function test_provider_file_ids_are_rejected_before_any_request(): void
    {
        $provider = $this->provider(new Response(200, body: '{"text":"ok"}'));

        try {
            $provider->chat(new UserMessage(new AudioContent('file-123', SourceType::ID)));
            $this->fail('File IDs cannot be transcribed.');
        } catch (ProviderException $exception) {
            $this->assertSame('Source type not supported: id', $exception->getMessage());
        }
        $this->assertSame([], $this->sentRequests);
    }

    public function test_stream_accumulates_deltas_and_reads_usage_from_the_done_event(): void
    {
        $provider = $this->provider(new Response(200, body: self::sseBody([
            ['type' => 'transcript.text.delta', 'delta' => 'Hello'],
            ['type' => 'transcript.text.segment', 'text' => 'ignored'],
            ['type' => 'transcript.text.delta', 'delta' => ' world'],
            ['type' => 'transcript.text.done', 'text' => 'Hello world', 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 2]],
        ])."data: [DONE]\n\n"));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage(new AudioContent('UklGRg==', SourceType::BASE64, 'audio/wav'))));

        $this->assertSame(['Hello', ' world'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertSame('Hello world', $message->getContent());
        $this->assertSame([12, 2], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($body['stream']);
    }

    public function test_structured_output_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Structured output is not supported for transcription.');

        $this->provider(new Response(200))->structured(new UserMessage('x'), 'Transcript', []);
    }
}
