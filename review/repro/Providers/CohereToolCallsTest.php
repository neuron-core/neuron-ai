<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Cohere;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function iterator_to_array;
use function json_encode;

class CohereToolCallsTest extends TestCase
{
    protected function provider(string $body): AIProviderInterface
    {
        return (new Cohere('key', 'command-a', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        )))->setTools([new ToolStub('lookup')]);
    }

    public function test_chat_tool_call_answer_without_content_uses_the_tool_plan(): void
    {
        $message = $this->provider('{"id":"r1","finish_reason":"TOOL_CALL","message":{"role":"assistant",'
            .'"tool_plan":"I will look it up","tool_calls":[{"id":"lookup_1","type":"function","function":{"name":"lookup","arguments":"{\"q\":\"rome\"}"}}]},'
            .'"usage":{"tokens":{"input_tokens":7,"output_tokens":3}}}')
            ->chat(new UserMessage('Where?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('I will look it up', $message->getContent());
        $this->assertSame(['q' => 'rome'], $message->getToolCalls()[0]->getInputs());
        $this->assertSame([7, 3], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_stream_keeps_every_parallel_tool_call_and_final_usage(): void
    {
        $events = [['type' => 'tool-plan-delta', 'delta' => ['message' => ['tool_plan' => 'Checking both.']]]];
        foreach (['rome', 'oslo'] as $index => $city) {
            $events[] = ['type' => 'tool-call-start', 'index' => $index, 'delta' => ['message' => ['tool_calls' => [
                'id' => "lookup_{$index}", 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => "{\"q\":\"{$city}\"}"],
            ]]]];
            $events[] = ['type' => 'tool-call-end', 'index' => $index];
        }
        $events[] = ['type' => 'message-end', 'delta' => ['finish_reason' => 'TOOL_CALL'], 'usage' => ['tokens' => ['input_tokens' => 11, 'output_tokens' => 4]]];
        $body = implode('', array_map(static fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events));

        $stream = $this->provider($body)->stream(new UserMessage('Weather?'));
        iterator_to_array($stream);
        $message = $stream->getReturn()->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking both.', $message->getContent());
        $this->assertSame(
            [['q' => 'rome'], ['q' => 'oslo']],
            array_map(static fn (ToolCall $call): array => $call->getInputs(), $message->getToolCalls())
        );
        $this->assertSame([11, 4], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }
}
