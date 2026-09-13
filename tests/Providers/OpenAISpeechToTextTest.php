<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\Audio\OpenAISpeechToText;
use PHPUnit\Framework\TestCase;

class OpenAISpeechToTextTest extends TestCase
{
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
        $stack = HandlerStack::create(new MockHandler([
            new Response(status: 200, body: $body),
        ]));

        return new OpenAISpeechToText(
            key: 'test-key',
            model: 'whisper-1',
            httpClient: new GuzzleHttpClient(handler: $stack),
        );
    }

    protected function audioMessage(): Message
    {
        return (new UserMessage('Transcribe this'))
            ->addContent(new AudioContent($this->audioFile, SourceType::URL));
    }

    public function test_chat_sets_usage_when_present(): void
    {
        $provider = $this->makeProvider('{"text":"Hello world","usage":{"type":"tokens","input_tokens":10,"output_tokens":20,"total_tokens":30}}');

        $message = $provider->chat($this->audioMessage());

        $this->assertSame('Hello world', $message->getContent());
        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(20, $message->getUsage()->outputTokens);
    }

    public function test_chat_without_usage_in_response(): void
    {
        $provider = $this->makeProvider('{"text":"Hello world"}');

        $message = $provider->chat($this->audioMessage());

        $this->assertSame('Hello world', $message->getContent());
        $this->assertNull($message->getUsage());
    }
}
