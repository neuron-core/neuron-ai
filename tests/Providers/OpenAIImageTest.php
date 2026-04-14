<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\Image\OpenAIImage;
use PHPUnit\Framework\TestCase;

use function json_decode;

class OpenAIImageTest extends TestCase
{
    public function test_chat_returns_image_message(): void
    {
        $body = '{"data":[{"b64_json":"FINAL_BASE64"}],"usage":{"input_tokens":10,"output_tokens":20,"total_tokens":30}}';

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $body),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = new OpenAIImage(
            key: 'test-key',
            model: 'gpt-image-1',
            output_format: 'webp',
            httpClient: new GuzzleHttpClient(handler: $stack),
        );

        $message = $provider->chat(new UserMessage('A cat'));

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $blocks = $message->getContentBlocks();
        $this->assertInstanceOf(ImageContent::class, $blocks[0]);
        $this->assertSame('FINAL_BASE64', $blocks[0]->content);
    }

    public function test_stream_yields_partials_and_uses_completed_event_for_final_image(): void
    {
        // Mock OpenAI Images streaming response:
        //   - partial_image_index is an integer per the API spec.
        //   - `completed` is the authoritative final image; no partial_image_index.
        $streamBody = 'data: {"type":"image_generation.partial_image","partial_image_index":0,"b64_json":"PARTIAL_ONE"}'."\n\n";
        $streamBody .= 'data: {"type":"image_generation.partial_image","partial_image_index":1,"b64_json":"PARTIAL_TWO"}'."\n\n";
        $streamBody .= 'data: {"type":"image_generation.completed","b64_json":"FINAL","usage":{"input_tokens":15,"output_tokens":100,"total_tokens":115}}'."\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = new OpenAIImage(
            key: 'test-key',
            model: 'gpt-image-1',
            output_format: 'webp',
            parameters: ['partial_images' => 2],
            httpClient: new GuzzleHttpClient(handler: $stack),
        );

        $generator = $provider->stream(new UserMessage('A cat'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        $message = $generator->getReturn();

        // Only partial events yield chunks; the completed event does not.
        $this->assertCount(2, $chunks);
        foreach ($chunks as $chunk) {
            $this->assertInstanceOf(ImageChunk::class, $chunk);
            // messageId is a non-empty string (StreamChunk contract).
            $this->assertIsString($chunk->messageId);
            $this->assertNotEmpty($chunk->messageId);
        }

        // All chunks in one stream share the same messageId.
        $this->assertSame($chunks[0]->messageId, $chunks[1]->messageId);

        // messageId uses the library-wide `msg_` prefix for consistency with
        // OpenAI text and audio providers.
        $this->assertStringStartsWith('msg_', $chunks[0]->messageId);

        // Chunk contents come directly from the API payload.
        $this->assertSame('PARTIAL_ONE', $chunks[0]->content);
        $this->assertSame('PARTIAL_TWO', $chunks[1]->content);

        // The final assistant message uses the completed image, not the last partial.
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $image = $message->getContentBlocks()[0];
        $this->assertInstanceOf(ImageContent::class, $image);
        $this->assertSame('FINAL', $image->content);
        $this->assertSame(SourceType::BASE64, $image->sourceType);
        $this->assertSame('image/webp', $image->mediaType);

        // Usage captured from the completed event only.
        $this->assertSame(15, $message->getUsage()->inputTokens);
        $this->assertSame(100, $message->getUsage()->outputTokens);
    }

    public function test_stream_supports_partial_images_zero_returning_only_completed(): void
    {
        $streamBody = 'data: {"type":"image_generation.completed","b64_json":"ONLY","usage":{"input_tokens":5,"output_tokens":42}}'."\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = new OpenAIImage(
            key: 'test-key',
            model: 'gpt-image-1',
            httpClient: new GuzzleHttpClient(handler: $stack),
        );

        $generator = $provider->stream(new UserMessage('A dog'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        $message = $generator->getReturn();

        $this->assertCount(0, $chunks);
        $image = $message->getContentBlocks()[0];
        $this->assertInstanceOf(ImageContent::class, $image);
        $this->assertSame('ONLY', $image->content);
        $this->assertSame(42, $message->getUsage()->outputTokens);
    }

    public function test_stream_sends_stream_flag_and_parameters_in_request_body(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);

        $streamBody = 'data: {"type":"image_generation.completed","b64_json":"ONLY"}'."\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = new OpenAIImage(
            key: 'test-key',
            model: 'gpt-image-1',
            output_format: 'png',
            parameters: ['partial_images' => 3, 'size' => '1024x1024'],
            httpClient: new GuzzleHttpClient(handler: $stack),
        );

        foreach ($provider->stream(new UserMessage('A fox')) as $chunk) {
            // drain generator
        }

        $this->assertCount(1, $sentRequests);
        $body = json_decode((string) $sentRequests[0]['request']->getBody(), true);
        $this->assertTrue($body['stream']);
        $this->assertSame(3, $body['partial_images']);
        $this->assertSame('1024x1024', $body['size']);
        $this->assertSame('A fox', $body['prompt']);
    }

    public function test_stream_throws_provider_exception_on_error_event(): void
    {
        // Real error payload shape as sent by the OpenAI Images streaming API.
        $streamBody = 'data: {"type":"error","error":{"type":"image_generation_server_error","code":"image_generation_failed","message":"Image generation failed","param":null}}'."\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = new OpenAIImage(
            key: 'test-key',
            model: 'gpt-image-1',
            httpClient: new GuzzleHttpClient(handler: $stack),
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Image generation failed');

        foreach ($provider->stream(new UserMessage('A fox')) as $chunk) {
            // drain generator — should throw before yielding
        }
    }

    public function test_stream_throws_when_stream_closes_before_completed_event(): void
    {
        // Stream drops after partials — no completed event. The old code would
        // silently return the last partial as the "final" image.
        $streamBody = 'data: {"type":"image_generation.partial_image","partial_image_index":0,"b64_json":"PARTIAL_ONE"}'."\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = new OpenAIImage(
            key: 'test-key',
            model: 'gpt-image-1',
            httpClient: new GuzzleHttpClient(handler: $stack),
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('stream ended before a completed image');

        $generator = $provider->stream(new UserMessage('A fox'));
        foreach ($generator as $chunk) {
            // drain
        }
        $generator->getReturn(); // triggers the post-loop assertion
    }

    public function test_stream_throws_when_completed_event_has_no_b64_json(): void
    {
        $streamBody = 'data: {"type":"image_generation.completed"}'."\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = new OpenAIImage(
            key: 'test-key',
            model: 'gpt-image-1',
            httpClient: new GuzzleHttpClient(handler: $stack),
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('completed image event without b64_json');

        foreach ($provider->stream(new UserMessage('A fox')) as $chunk) {
            // drain
        }
    }
}
