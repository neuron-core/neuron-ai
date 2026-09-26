<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Deepseek;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class DeepseekStreamStateLeakTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    protected function streamWithReasoning(string $reasoning): string
    {
        return self::sseBody([
            ['choices' => [['index' => 0, 'delta' => ['reasoning_content' => $reasoning]]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Answer A'], 'finish_reason' => 'stop']]],
        ]);
    }

    public function test_reasoning_of_a_previous_stream_does_not_leak_into_a_later_chat_answer(): void
    {
        $chat = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer B"}}]}';
        $provider = new Deepseek('sk-test', 'deepseek-reasoner', httpClient: $this->recordingClient(
            new Response(200, body: $this->streamWithReasoning('Private reasoning about user A')),
            new Response(200, body: $chat),
        ));

        $this->consumeStream($provider->stream(new UserMessage('Question A')));
        $answer = $provider->chat(new UserMessage('Question B'))->message();

        $this->assertNull($answer->getMetadata('reasoning_content'));
    }

    public function test_reasoning_of_a_previous_stream_does_not_override_the_reasoning_of_a_later_chat_answer(): void
    {
        $chat = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Answer B","reasoning_content":"Reasoning B"}}]}';
        $provider = new Deepseek('sk-test', 'deepseek-reasoner', httpClient: $this->recordingClient(
            new Response(200, body: $this->streamWithReasoning('Private reasoning about user A')),
            new Response(200, body: $chat),
        ));

        $this->consumeStream($provider->stream(new UserMessage('Question A')));
        $answer = $provider->chat(new UserMessage('Question B'))->message();

        $this->assertSame('Reasoning B', $answer->getMetadata('reasoning_content'));
    }
}
