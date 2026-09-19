<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LogicException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\ZAI\ZAI;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function array_map;

class ZAITest extends TestCase
{
    public function test_chat_uses_default_base_uri(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model":"glm-5.2","choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Hello!"}}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new ZAI('', 'glm-5.2'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response);

        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0]['request'];
        $this->assertSame('chat/completions', (string) $request->getUri());
    }

    public function test_chat_uses_custom_coding_base_uri(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model":"glm-5.2","choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Hello!"}}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new ZAI(
            key: '',
            model: 'glm-5.2',
            baseUri: 'https://api.z.ai/api/coding/paas/v4',
        ))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response);

        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0]['request'];
        $this->assertSame('chat/completions', (string) $request->getUri());
    }

    public function test_chat_preserves_reasoning_content_for_continuation(): void
    {
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model":"glm-5.2","choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Hello!","reasoning_content":"Greet the user."}}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
            ),
        ]);
        $provider = (new ZAI('', 'glm-5.2'))->setHttpClient(
            new GuzzleHttpClient(handler: HandlerStack::create($mockHandler)),
        );

        $response = $provider->chat(new UserMessage('Hi'));

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $this->assertSame('Greet the user.', $response->getMetadata('reasoning_content'));
        $this->assertSame('Greet the user.', $provider->messageMapper()->map([$response])[0]['reasoning_content']);
    }

    public function test_stream_preserves_reasoning_content_for_tool_call_continuation(): void
    {
        $streamBody = "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{\"reasoning_content\":\"Inspect \"},\"finish_reason\":null}]}\n\n";
        $streamBody .= "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{\"reasoning_content\":\"schema. \",\"tool_calls\":[{\"index\":0,\"id\":\"call-123\",\"type\":\"function\",\"function\":{\"name\":\"inspect_schema\",\"arguments\":\"{}\"}}]},\"finish_reason\":null}]}\n\n";
        $streamBody .= "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = (new ZAI('', 'glm-5.2'))
            ->setTools([Tool::make('inspect_schema', 'Inspect the database schema.')])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $generator = $provider->stream(new UserMessage('Inspect the database schema.'));
        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }
        $message = $generator->getReturn();

        $this->assertContainsOnlyInstancesOf(ReasoningChunk::class, $chunks);
        $this->assertSame(['Inspect ', 'schema. '], array_map(
            static fn (StreamChunk $chunk): string => $chunk instanceof ReasoningChunk
                ? $chunk->content
                : throw new LogicException('Only reasoning chunks are expected.'),
            $chunks,
        ));
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Inspect schema. ', $message->getMetadata('reasoning_content'));
        $this->assertInstanceOf(ReasoningContent::class, $message->getReasoning());

        $messages = $provider->messageMapper()->map([$message]);
        $this->assertSame('Inspect schema. ', $messages[0]['reasoning_content']);
    }
}
