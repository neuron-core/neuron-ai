<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ProviderToolInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function iterator_to_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * streamGenerateContent (without alt=sse) answers with one JSON array whose
 * elements arrive as they are generated: "[{...}\r\n,\r\n{...}]".
 */
class GeminiStreamTest extends TestCase
{
    use RecordsHttpRequests;
    use ConsumesProviderStreams;

    protected const SECRET = 'AIza-SECRET-0123456789';

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected static function jsonArrayBody(array $events): string
    {
        return "[".implode("\r\n,\r\n", array_map(
            static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $events,
        ))."]";
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    protected static function candidate(array $parts, ?string $finishReason = null): array
    {
        $candidate = ['content' => ['role' => 'model', 'parts' => $parts]];
        if ($finishReason !== null) {
            $candidate['finishReason'] = $finishReason;
        }

        return ['candidates' => [$candidate]];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected function provider(array $events): Gemini
    {
        return new Gemini(self::SECRET, 'gemini-2.5-flash', httpClient: $this->recordingClient(
            new Response(200, body: self::jsonArrayBody($events)),
        ));
    }

    public function test_stream_request_targets_stream_endpoint_with_key_in_header_only(): void
    {
        $provider = $this->provider([self::candidate([['text' => 'Hi']], 'STOP')]);
        $provider->systemPrompt('Be brief');

        $this->consumeStream($provider->stream(new UserMessage('Hello')));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(
            ['POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent'],
            $this->sentTargets(),
        );
        $this->assertSame(self::SECRET, $request->getHeaderLine('x-goog-api-key'));
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getUri());
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getBody());
        $this->assertSame([
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Hello']]]],
            'system_instruction' => ['parts' => [['text' => 'Be brief']]],
        ], json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_text_fragments_are_yielded_in_order_and_accumulated(): void
    {
        $provider = $this->provider([
            self::candidate([['text' => 'Hel']]),
            self::candidate([['text' => 'lo, ']]),
            self::candidate([['text' => 'world']], 'STOP') + [
                'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 3, 'cachedContentTokenCount' => 2],
            ],
        ]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Hel', 'lo, ', 'world'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Hello, world', $message->getContent());
        $this->assertSame('STOP', $message->stopReason());
        $this->assertSame(7, $message->getUsage()->inputTokens);
        $this->assertSame(3, $message->getUsage()->outputTokens);
        $this->assertSame(2, $message->getUsage()->cachedInputTokens);
        foreach ($chunks as $chunk) {
            $this->assertSame($message->getId(), $chunk->messageId);
        }
    }

    public function test_json_delimiters_and_multibyte_text_inside_strings_do_not_break_object_framing(): void
    {
        $tricky = 'curly } and { brackets ] [ "quoted" \\ backslash — 日本語 🚀';
        $provider = $this->provider([
            self::candidate([['text' => $tricky]]),
            self::candidate([['text' => '!']], 'STOP'),
        ]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame([$tricky, '!'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertSame($tricky.'!', $message->getContent());
    }

    public function test_last_finish_reason_is_authoritative(): void
    {
        $provider = $this->provider([
            self::candidate([['text' => 'partial']], 'FINISH_REASON_UNSPECIFIED'),
            self::candidate([['text' => ' cut']], 'MAX_TOKENS'),
        ]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('partial cut', $message->getContent());
        $this->assertSame('MAX_TOKENS', $message->stopReason());
    }

    public function test_stream_without_finish_reason_leaves_stop_reason_unset(): void
    {
        $provider = $this->provider([self::candidate([['text' => 'x']])]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNull($message->stopReason());
    }

    public function test_usage_requires_both_prompt_and_candidate_counts(): void
    {
        $provider = $this->provider([
            self::candidate([['text' => 'a']]) + ['usageMetadata' => ['promptTokenCount' => 99]],
            self::candidate([['text' => 'b']], 'STOP'),
        ]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(0, $message->getUsage()->inputTokens);
        $this->assertSame(0, $message->getUsage()->outputTokens);
    }

    public function test_error_object_mid_stream_raises_provider_exception_with_api_message(): void
    {
        $provider = $this->provider([
            self::candidate([['text' => 'partial']]),
            ['error' => ['code' => 500, 'message' => 'Internal error encountered.', 'status' => 'INTERNAL']],
        ]);

        $stream = $provider->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Gemini API Error (Streaming): Internal error encountered.');
        iterator_to_array($stream);
    }

    public function test_error_object_without_message_is_reported_as_json(): void
    {
        $provider = $this->provider([['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']]]);

        $stream = $provider->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Gemini API Error (Streaming): {"code":429,"status":"RESOURCE_EXHAUSTED"}');
        iterator_to_array($stream);
    }

    public function test_http_error_raises_http_exception_without_the_api_key(): void
    {
        $provider = new Gemini(self::SECRET, 'gemini-2.5-flash', httpClient: $this->recordingClient(
            new Response(400, body: '{"error":{"code":400,"message":"API key not valid.","status":"INVALID_ARGUMENT"}}'),
        ));

        try {
            iterator_to_array($provider->stream(new UserMessage('Hi')));
            $this->fail('A 400 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->response?->statusCode);
            $this->assertStringContainsString('API key not valid.', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function test_reasoning_then_text_builds_ordered_blocks(): void
    {
        $provider = $this->provider([
            self::candidate([['text' => 'thinking...', 'thought' => true]]),
            self::candidate([['text' => 'answer']], 'STOP'),
        ]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $blocks = $message->getContentBlocks();
        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(ReasoningContent::class, $blocks[0]);
        $this->assertSame('thinking...', $blocks[0]->content);
        $this->assertInstanceOf(TextContent::class, $blocks[1]);
        $this->assertNotInstanceOf(ReasoningContent::class, $blocks[1]);
        $this->assertSame('answer', $blocks[1]->content);
    }

    public function test_inline_image_part_becomes_base64_image_block(): void
    {
        $provider = $this->provider([
            self::candidate([['text' => 'Here it is']]),
            self::candidate([['inlineData' => ['mimeType' => 'image/png', 'data' => 'iVBORw0KGgo=']]], 'STOP'),
        ]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Draw')));

        $this->assertSame(['Here it is'], $this->contentsOf(TextChunk::class, $chunks));
        $image = $message->getImage();
        $this->assertInstanceOf(ImageContent::class, $image);
        $this->assertSame('iVBORw0KGgo=', $image->content);
        $this->assertSame(SourceType::BASE64, $image->sourceType);
        $this->assertSame('image/png', $image->mediaType);
    }

    public function test_file_data_part_becomes_url_file_block(): void
    {
        $provider = $this->provider([
            self::candidate([['fileData' => ['mimeType' => 'application/pdf', 'fileUri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc']]], 'STOP'),
        ]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Summarize')));

        $this->assertSame([], $chunks);
        [$file] = $message->getContentBlocks();
        $this->assertInstanceOf(FileContent::class, $file);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/files/abc', $file->content);
        $this->assertSame(SourceType::URL, $file->sourceType);
        $this->assertSame('application/pdf', $file->mediaType);
    }

    public function test_tool_call_with_finish_reason_in_the_same_chunk_returns_tool_call_message(): void
    {
        $provider = $this->provider([
            self::candidate([['text' => 'Let me check.']]),
            self::candidate([
                ['functionCall' => ['id' => 'fc-1', 'name' => 'get_weather', 'args' => ['city' => 'Rome']], 'thoughtSignature' => 'sig-1'],
            ], 'STOP') + ['usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]],
            // Anything after the finishing tool call chunk is never read.
            self::candidate([['text' => 'ignored']], 'STOP'),
        ]);
        $provider->setTools([new ToolStub('get_weather', 'Weather lookup')]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Weather?')));

        $this->assertSame(['Let me check.'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Let me check.', $message->getContent());
        $this->assertSame('sig-1', $message->getMetadata('thought_signature'));
        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(5, $message->getUsage()->outputTokens);
        [$call] = $message->getToolCalls();
        $this->assertSame('get_weather', $call->getName());
        $this->assertSame('fc-1', $call->getCallId());
        $this->assertSame(['city' => 'Rome'], $call->getInputs());
        $this->assertSame('Weather lookup', $call->getDescription());
    }

    public function test_tool_calls_split_across_chunks_finish_on_a_separate_stop_chunk(): void
    {
        $provider = $this->provider([
            self::candidate([['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome']]]]),
            self::candidate([['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Milan']]]]),
            self::candidate([['text' => '']], 'STOP'),
        ]);
        $provider->setTools([new ToolStub('get_weather')]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Weather?')));

        $this->assertSame([], $chunks);
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $calls = $message->getToolCalls();
        $this->assertCount(2, $calls);
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame(['city' => 'Milan'], $calls[1]->getInputs());
        $this->assertNotSame($calls[0]->getCallId(), $calls[1]->getCallId());
    }

    public function test_tool_call_for_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider([
            self::candidate([['functionCall' => ['name' => 'run_code', 'args' => ['code' => 'rm -rf /']]]], 'STOP'),
        ]);
        $provider->setTools([new ToolStub('get_weather')]);

        $stream = $provider->stream(new UserMessage('Weather?'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: run_code.');
        iterator_to_array($stream);
    }

    public function test_grounding_metadata_is_attached_as_citations(): void
    {
        $provider = $this->provider([
            [
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Rome is the capital.']]],
                    'finishReason' => 'STOP',
                    'groundingMetadata' => [
                        'groundingChunks' => [['web' => ['uri' => 'https://example.com/rome', 'title' => 'Rome']]],
                    ],
                ]],
            ],
        ]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Capital of Italy?')));

        $citations = $message->getMetadata('citations');
        $this->assertCount(1, $citations);
        $this->assertInstanceOf(Citation::class, $citations[0]);
        $this->assertSame('gemini_chunk_0', $citations[0]->id);
        $this->assertSame('https://example.com/rome', $citations[0]->source);
        $this->assertSame('Rome', $citations[0]->title);
    }

    public function test_tools_payload_enables_auto_function_calling(): void
    {
        $provider = $this->provider([self::candidate([['text' => 'ok']], 'STOP')]);
        $provider->setTools([new ToolStub('get_weather', 'Weather lookup')]);

        $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['functionCallingConfig' => ['mode' => 'AUTO']], $body['toolConfig']);
        $this->assertSame('get_weather', $body['tools']['functionDeclarations'][0]['name']);
    }

    /**
     * Untyped on purpose: AIProviderInterface::setTools() documents ToolInterface[] only,
     * while providers accept provider tools too.
     */
    protected static function providerTools(ProviderToolInterface ...$tools): array
    {
        return $tools;
    }

    public function test_provider_tools_alone_are_sent_without_function_calling_config(): void
    {
        $provider = $this->provider([self::candidate([['text' => 'ok']], 'STOP')]);
        $provider->setTools(self::providerTools(new ProviderTool('google_search')));

        $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(
            '{"contents":[{"role":"user","parts":[{"text":"Hi"}]}],"tools":[{"google_search":{}}]}',
            (string) $this->sentRequests[0]['request']->getBody(),
        );
    }
}
