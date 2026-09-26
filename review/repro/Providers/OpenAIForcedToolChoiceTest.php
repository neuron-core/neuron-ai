<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class OpenAIForcedToolChoiceTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    protected const FORCED_TOOL_CHOICE = ['tool_choice' => ['type' => 'function', 'function' => ['name' => 'weather']]];

    public function test_chat_honours_tool_calls_when_finish_reason_is_stop(): void
    {
        // With tool_choice forcing a function, OpenAI (and many compatible servers) answer finish_reason "stop".
        $body = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":null,'
            .'"tool_calls":[{"id":"call_1","type":"function","function":{"name":"weather","arguments":"{\"city\":\"Rome\"}"}}]}}]}';
        $provider = new OpenAI('sk-test', 'gpt-test', self::FORCED_TOOL_CHOICE, httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('weather')]);

        $message = $provider->chat(new UserMessage('Weather in Rome?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('call_1', $message->getToolCalls()[0]->getCallId());
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_stream_honours_tool_calls_when_finish_reason_is_stop(): void
    {
        $body = self::sseBody([
            ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Rome"}']]]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ])."data: [DONE]\n\n";
        $provider = new OpenAI('sk-test', 'gpt-test', self::FORCED_TOOL_CHOICE, httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('weather')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Weather in Rome?')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('call_1', $message->getToolCalls()[0]->getCallId());
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }
}
