<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ElevenLabs;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ElevenLabs\ElevenLabsSpeechToText;
use NeuronAI\Providers\ElevenLabs\ElevenLabsTextToSpeech;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function iterator_to_array;

class ElevenLabsUnsupportedOperationsTest extends TestCase
{
    /**
     * @return array<string, array{AIProviderInterface}>
     */
    public static function providers(): array
    {
        return [
            'speech to text' => [new ElevenLabsSpeechToText('key', 'scribe_v1')],
            'text to speech' => [new ElevenLabsTextToSpeech('key', 'eleven_multilingual_v2', 'voice-123')],
        ];
    }

    #[DataProvider('providers')]
    public function test_structured_error_names_elevenlabs_not_openai(AIProviderInterface $provider): void
    {
        try {
            $provider->structured([new UserMessage('hi')], stdClass::class, []);
            $this->fail('Expected ProviderException');
        } catch (ProviderException $exception) {
            $this->assertStringContainsString('ElevenLabs', $exception->getMessage());
            $this->assertStringNotContainsString('OpenAI', $exception->getMessage());
        }
    }

    public function test_speech_to_text_stream_error_names_elevenlabs_speech_to_text(): void
    {
        $provider = new ElevenLabsSpeechToText('key', 'scribe_v1');

        try {
            iterator_to_array($provider->stream(new UserMessage('hi')));
            $this->fail('Expected ProviderException');
        } catch (ProviderException $exception) {
            $this->assertSame('Streaming is not supported by ElevenLabs Speech to Text.', $exception->getMessage());
        }
    }
}
