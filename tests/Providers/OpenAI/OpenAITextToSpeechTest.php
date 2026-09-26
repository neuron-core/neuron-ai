<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\Audio\OpenAITextToSpeech;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class OpenAITextToSpeechTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    /**
     * @param array<string, mixed> $parameters
     */
    protected function provider(string $body, array $parameters = []): OpenAITextToSpeech
    {
        return new OpenAITextToSpeech('sk-test', 'gpt-4o-mini-tts', 'alloy', $parameters, $this->recordingClient(new Response(200, body: $body)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_chat_sends_the_last_message_as_input_with_voice_and_instructions(): void
    {
        $provider = $this->provider("\x00\x01binary-mp3")->systemPrompt('Speak slowly');

        $provider->chat(new UserMessage('Ignored'), new UserMessage('Read this aloud'));

        $this->assertSame(['POST https://api.openai.com/v1/audio/speech'], $this->sentTargets());
        $this->assertSame([
            'model' => 'gpt-4o-mini-tts',
            'input' => 'Read this aloud',
            'voice' => 'alloy',
            'instructions' => 'Speak slowly',
        ], $this->sentBody());
    }

    public function test_chat_returns_the_binary_audio_base64_encoded(): void
    {
        $message = $this->provider("\x00\x01binary-mp3")->chat(new UserMessage('Hi'))->message();

        $audio = $message->getAudio();
        $this->assertInstanceOf(AudioContent::class, $audio);
        $this->assertSame(SourceType::BASE64, $audio->sourceType);
        $this->assertSame(base64_encode("\x00\x01binary-mp3"), $audio->content);
    }

    public function test_parameters_are_merged_and_instructions_default_to_empty(): void
    {
        $this->provider('mp3', ['response_format' => 'wav', 'speed' => 1.5])->chat(new UserMessage('Hi'));

        $body = $this->sentBody();
        $this->assertSame('', $body['instructions']);
        $this->assertSame('wav', $body['response_format']);
        $this->assertSame(1.5, $body['speed']);
    }

    public function test_stream_concatenates_audio_deltas_and_reads_usage_from_the_done_event(): void
    {
        $body = self::sseBody([
            ['type' => 'speech.audio.delta', 'audio' => 'QUJD'],
            ['type' => 'speech.audio.delta', 'audio' => 'REVG'],
            ['type' => 'speech.audio.done', 'usage' => ['input_tokens' => 4, 'output_tokens' => 30, 'total_tokens' => 34]],
        ]);

        [$chunks, $message] = $this->consumeStream($this->provider($body)->stream(new UserMessage('Hi')));

        $this->assertSame(['QUJD', 'REVG'], $this->contentsOf(AudioChunk::class, $chunks));
        $this->assertSame('QUJDREVG', $message->getAudio()->content);
        $this->assertSame(4, $message->getUsage()->inputTokens);
        $this->assertSame(30, $message->getUsage()->outputTokens);
        $this->assertTrue($this->sentBody()['stream']);
    }

    public function test_structured_output_is_not_supported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Structured output is not supported');

        $this->provider('')->structured(new UserMessage('Hi'), 'Person', []);
    }
}
