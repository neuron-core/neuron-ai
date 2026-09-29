<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class OpenAISpeechToTextTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    protected string $audioFile;

    protected function setUp(): void
    {
        $this->audioFile = tempnam(sys_get_temp_dir(), 'neuron_audio_');
        file_put_contents($this->audioFile, 'fake-audio');
    }

    protected function tearDown(): void
    {
        @unlink($this->audioFile);
    }

    protected function makeProvider(string $body): OpenAISpeechToText
    {
        return new OpenAISpeechToText(
            key: 'test-key',
            model: 'whisper-1',
            language: 'it',
            httpClient: $this->recordingClient(new Response(status: 200, body: $body)),
        );
    }

    protected function sentBody(): string
    {
        return (string) $this->sentRequests[0]['request']->getBody();
    }

    protected function audioMessage(): Message
    {
        return (new UserMessage('Transcribe this'))
            ->addContent(new AudioContent($this->audioFile, SourceType::URL));
    }

    public function test_chat_sets_usage_when_present(): void
    {
        $provider = $this->makeProvider('{"text":"Hello world","usage":{"type":"tokens","input_tokens":10,"output_tokens":20,"total_tokens":30}}');

        $message = $provider->chat($this->audioMessage())->message();

        $this->assertSame('Hello world', $message->getContent());
        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(20, $message->getUsage()->outputTokens);
    }

    public function test_chat_without_usage_in_response(): void
    {
        $provider = $this->makeProvider('{"text":"Hello world"}');

        $message = $provider->chat($this->audioMessage())->message();

        $this->assertSame('Hello world', $message->getContent());
        $this->assertNull($message->getUsage());
    }

    public function test_chat_uploads_the_audio_file_as_multipart_with_model_language_and_prompt(): void
    {
        $this->makeProvider('{"text":"Ciao"}')->chat(new UserMessage('Hi'), $this->audioMessage());

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.openai.com/v1/audio/transcriptions'], $this->sentTargets());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        $body = $this->sentBody();
        $this->assertMatchesRegularExpression('/name="file"; filename="[^"]+"\r\n.*\r\n\r\nfake-audio\r\n/s', $body);
        $this->assertMatchesRegularExpression('/name="model"\r\n(?:[^\r\n]+\r\n)*\r\nwhisper-1\r\n/', $body);
        $this->assertMatchesRegularExpression('/name="language"\r\n(?:[^\r\n]+\r\n)*\r\nit\r\n/', $body);
        $this->assertMatchesRegularExpression('/name="response_format"\r\n(?:[^\r\n]+\r\n)*\r\njson\r\n/', $body);
        $this->assertMatchesRegularExpression('/name="prompt"\r\n(?:[^\r\n]+\r\n)*\r\nTranscribe this\r\n/', $body);
        $this->assertStringNotContainsString('name="stream"', $body);
    }

    public function test_chat_without_text_sends_no_prompt(): void
    {
        $message = (new UserMessage([]))->addContent(new AudioContent($this->audioFile, SourceType::URL));

        $this->makeProvider('{"text":"Ciao"}')->chat($message);

        $this->assertStringNotContainsString('name="prompt"', $this->sentBody());
    }

    public function test_stream_concatenates_transcript_deltas_and_reads_usage_from_the_done_event(): void
    {
        $body = self::sseBody([
            ['type' => 'transcript.text.delta', 'delta' => 'Hello'],
            ['type' => 'transcript.text.delta', 'delta' => ' world'],
            ['type' => 'transcript.text.done', 'text' => 'Hello world', 'usage' => ['input_tokens' => 7, 'output_tokens' => 2]],
        ]);

        [$chunks, $message] = $this->consumeStream($this->makeProvider($body)->stream($this->audioMessage()));

        $this->assertSame(['Hello', ' world'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertSame('Hello world', $message->getContent());
        $this->assertSame(7, $message->getUsage()->inputTokens);
        $this->assertSame(2, $message->getUsage()->outputTokens);
        $this->assertMatchesRegularExpression('/name="stream"\r\n(?:[^\r\n]+\r\n)*\r\n1\r\n/', $this->sentBody());
    }

    public function test_structured_output_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Structured output is not supported');

        $this->makeProvider('{}')->structured($this->audioMessage(), 'Person', []);
    }

    public function test_configured_parameters_are_uploaded_as_form_fields(): void
    {
        $provider = new OpenAISpeechToText('test-key', 'whisper-1', 'it', ['temperature' => '0.2'], $this->recordingClient(new Response(200, body: '{"text":"ok"}')));

        $provider->chat($this->audioMessage());

        $this->assertMatchesRegularExpression('/name="temperature"\r\n(?:[^\r\n]+\r\n)*\r\n0.2\r\n/', $this->sentBody());
    }

    public function test_configured_parameters_are_uploaded_when_streaming(): void
    {
        $body = self::sseBody([['type' => 'transcript.text.done', 'text' => 'ok']]);
        $provider = new OpenAISpeechToText('test-key', 'whisper-1', 'it', ['temperature' => '0.2'], $this->recordingClient(new Response(200, body: $body)));

        $this->consumeStream($provider->stream($this->audioMessage()));

        $this->assertMatchesRegularExpression('/name="temperature"\r\n(?:[^\r\n]+\r\n)*\r\n0.2\r\n/', $this->sentBody());
    }

    public function test_base64_audio_is_uploaded_decoded_and_named_after_its_format(): void
    {
        $message = (new UserMessage('Transcribe'))->addContent(new AudioContent(base64_encode('RIFF-fake-wav'), SourceType::BASE64, 'audio/wav'));

        $this->makeProvider('{"text":"Hi"}')->chat($message);

        $request = $this->sentRequests[0]['request'];
        $this->assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $this->assertMatchesRegularExpression('/name="file"; filename="audio\.wav"\r\n(?:[^\r\n]+\r\n)*\r\nRIFF-fake-wav\r\n/', $this->sentBody());
    }

    public function test_base64_audio_is_uploaded_when_streaming(): void
    {
        $message = (new UserMessage('Transcribe'))->addContent(new AudioContent(base64_encode('ID3-fake-mp3'), SourceType::BASE64, 'audio/mpeg'));

        $this->consumeStream($this->makeProvider(self::sseBody([['type' => 'transcript.text.done', 'text' => 'ok']]))->stream($message));

        $this->assertMatchesRegularExpression('/name="file"; filename="audio\.mp3"\r\n(?:[^\r\n]+\r\n)*\r\nID3-fake-mp3\r\n/', $this->sentBody());
    }

    /**
     * @return iterable<string, array{AudioContent, string}>
     */
    public static function unusableAudio(): iterable
    {
        yield 'base64 without a media type' => [new AudioContent(base64_encode('RIFF'), SourceType::BASE64), 'Base64 audio needs a media type'];
        yield 'invalid base64' => [new AudioContent('not base64!', SourceType::BASE64, 'audio/wav'), 'The audio is not valid base64'];
        yield 'provider file id' => [new AudioContent('file-123', SourceType::ID, 'audio/wav'), 'Audio must be a file path or base64'];
        yield 'unreadable path' => [new AudioContent('/no/such/recording.wav', SourceType::URL, 'audio/wav'), 'Cannot open the audio file: /no/such/recording.wav'];
    }

    #[DataProvider('unusableAudio')]
    public function test_unusable_audio_is_refused_before_any_request(AudioContent $audio, string $reason): void
    {
        try {
            $this->makeProvider('{"text":"Hi"}')->chat(new UserMessage($audio));
            $this->fail('The audio must be refused.');
        } catch (ProviderException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        $this->assertSame([], $this->sentRequests);
    }
}
