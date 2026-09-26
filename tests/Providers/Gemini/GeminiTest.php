<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Citation;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tests\Support\PhpWarningsAsExceptions;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class GeminiTest extends TestCase
{
    use RecordsHttpRequests;
    use PhpWarningsAsExceptions;

    protected const SECRET = 'AIza-SECRET-0123456789';

    protected string $body = '{
	"candidates": [
		{
			"content": {
			    "role": "model",
			    "parts": [
                    {
                        "text": "test response"
                    }
                ]
			},
			"finishReason": "STOP"
		}
	]
}';

    public function test_chat_request(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response->message());

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Hi'],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->message()->getContent());
        $this->assertSame('STOP', $response->message()->stopReason());
    }

    public function test_chat_with_url_image(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this image'))
            ->addContent(new ImageContent(
                content: '/test.png',
                sourceType: SourceType::URL,
                mediaType: 'image/png'
            ));

        $response = $provider->chat($message);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Describe this image'],
                        ['file_data' => ['file_uri' => '/test.png', 'mime_type' => 'image/png']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->message()->getContent());
    }

    public function test_chat_with_base64_image(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this image'))
            ->addContent(new ImageContent(
                content: 'base64_encoded_image_data',
                sourceType: SourceType::BASE64,
                mediaType: 'image/png'
            ));

        $response = $provider->chat($message);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Describe this image'],
                        ['inline_data' => ['data' => 'base64_encoded_image_data', 'mime_type' => 'image/png']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->message()->getContent());
    }

    public function test_chat_with_url_document(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this document'))
            ->addContent(new FileContent(
                content: '/test.pdf',
                sourceType: SourceType::URL,
                mediaType: 'application/pdf'
            ));

        $response = $provider->chat($message);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Describe this document'],
                        ['file_data' => ['file_uri' => '/test.pdf', 'mime_type' => 'application/pdf']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->message()->getContent());
    }

    public function test_chat_with_base64_document(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this document'))
            ->addContent(new FileContent(
                content: 'base64_encoded_document_data',
                sourceType: SourceType::BASE64,
                mediaType: 'application/pdf'
            ));

        $response = $provider->chat($message);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Describe this document'],
                        ['inline_data' => ['data' => 'base64_encoded_document_data', 'mime_type' => 'application/pdf']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->message()->getContent());
    }

    public function test_tools_payload(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Gemini('', 'gemini-2.0-flash'))
            ->setTools([
                (new ToolStub('tool', description: 'description'))
                    ->addProperty(
                        new ToolProperty(
                            'prop',
                            PropertyType::STRING,
                            'description',
                            true
                        )
                    ),
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat(new UserMessage('Hi'));

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Hi'],
                    ],
                ],
            ],
            'tools' => [
                'functionDeclarations' => [
                    [
                        'name' => 'tool',
                        'description' => 'description',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'prop' => [
                                    'type' => 'string',
                                    'description' => 'description',
                                ],
                            ],
                            'required' => ['prop'],
                        ],
                    ],
                ],
            ],
            'toolConfig' => [
                'functionCallingConfig' => [
                    'mode' => 'AUTO',
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
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
        $provider = $this->provider(self::answer([['text' => 'ok']]));
        $provider->setTools(self::providerTools(new ProviderTool('google_search')));

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(
            '{"contents":[{"role":"user","parts":[{"text":"Hi"}]}],"tools":[{"google_search":{}}]}',
            (string) $this->sentRequests[0]['request']->getBody(),
        );
    }

    public function test_parallel_calls_of_the_same_tool_get_distinct_call_ids(): void
    {
        $body = '{
            "candidates": [
                {
                    "content": {
                        "role": "model",
                        "parts": [
                            {"functionCall": {"name": "get_weather", "args": {"city": "Rome"}}},
                            {"functionCall": {"name": "get_weather", "args": {"city": "Milan"}}}
                        ]
                    },
                    "finishReason": "STOP"
                }
            ]
        }';

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $body),
        ]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))
            ->setTools([new ToolStub('get_weather', description: 'description')])
            ->setHttpClient(new GuzzleHttpClient(handler: HandlerStack::create($mockHandler)));

        $message = $provider->chat(new UserMessage('Weather in Rome and Milan?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $tools = $message->getToolCalls();
        $this->assertCount(2, $tools);
        $this->assertSame(['city' => 'Rome'], $tools[0]->getInputs());
        $this->assertSame(['city' => 'Milan'], $tools[1]->getInputs());
        $this->assertNotNull($tools[0]->getCallId());
        $this->assertNotNull($tools[1]->getCallId());
        $this->assertNotSame($tools[0]->getCallId(), $tools[1]->getCallId());
    }

    public function test_api_provided_function_call_id_is_used_as_call_id(): void
    {
        $body = '{
            "candidates": [
                {
                    "content": {
                        "role": "model",
                        "parts": [
                            {"functionCall": {"id": "fc_1", "name": "get_weather", "args": {"city": "Rome"}}},
                            {"functionCall": {"id": "fc_2", "name": "get_weather", "args": {"city": "Milan"}}}
                        ]
                    },
                    "finishReason": "STOP"
                }
            ]
        }';

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $body),
        ]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))
            ->setTools([new ToolStub('get_weather', description: 'description')])
            ->setHttpClient(new GuzzleHttpClient(handler: HandlerStack::create($mockHandler)));

        $message = $provider->chat(new UserMessage('Weather in Rome and Milan?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('fc_1', $message->getToolCalls()[0]->getCallId());
        $this->assertSame('fc_2', $message->getToolCalls()[1]->getCallId());
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function provider(array $response, string $baseUri = 'https://generativelanguage.googleapis.com/v1beta/models'): Gemini
    {
        return new Gemini(self::SECRET, 'gemini-2.5-flash', httpClient: $this->recordingClient(
            new Response(200, body: json_encode($response, JSON_THROW_ON_ERROR)),
        ), baseUri: $baseUri);
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    protected static function answer(array $parts, string $finishReason = 'STOP'): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finishReason]]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_api_key_travels_only_in_the_goog_api_key_header(): void
    {
        $provider = $this->provider(self::answer([['text' => 'ok']]));

        $provider->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(
            ['POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'],
            $this->sentTargets(),
        );
        $this->assertSame(self::SECRET, $request->getHeaderLine('x-goog-api-key'));
        $this->assertSame('', $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getUri());
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getBody());
    }

    public function test_custom_base_uri_trailing_slash_is_trimmed(): void
    {
        $provider = $this->provider(self::answer([['text' => 'ok']]), 'https://proxy.internal/gemini/');

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['POST https://proxy.internal/gemini/gemini-2.5-flash:generateContent'], $this->sentTargets());
    }

    public function test_failed_request_does_not_expose_the_api_key(): void
    {
        $provider = new Gemini(self::SECRET, 'gemini-2.5-flash', httpClient: $this->recordingClient(
            new Response(400, body: '{"error":{"code":400,"message":"API key not valid.","status":"INVALID_ARGUMENT"}}'),
        ));

        try {
            $provider->chat(new UserMessage('Hi'));
            $this->fail('A 400 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->response?->statusCode);
            $this->assertStringContainsString('API key not valid.', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function test_system_prompt_is_sent_as_system_instruction_and_parameters_are_merged(): void
    {
        $provider = new Gemini(
            self::SECRET,
            'gemini-2.5-flash',
            parameters: ['generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 64]],
            httpClient: $this->recordingClient(new Response(200, body: json_encode(self::answer([['text' => 'ok']]), JSON_THROW_ON_ERROR))),
        );
        $provider->systemPrompt(new SystemMessage('You are terse.'));

        $provider->chat(new UserMessage('Hi'), new AssistantMessage('Hello'), new UserMessage('Again'));

        $this->assertSame([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Hi']]],
                ['role' => 'model', 'parts' => [['text' => 'Hello']]],
                ['role' => 'user', 'parts' => [['text' => 'Again']]],
            ],
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 64],
            'system_instruction' => ['parts' => [['text' => 'You are terse.']]],
        ], $this->sentBody());
    }

    public function test_null_system_prompt_removes_the_system_instruction(): void
    {
        $provider = $this->provider(self::answer([['text' => 'ok']]));
        $provider->systemPrompt('first')->systemPrompt(null);

        $provider->chat(new UserMessage('Hi'));

        $this->assertArrayNotHasKey('system_instruction', $this->sentBody());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unusable_responses(): array
    {
        return [
            'api error with message' => [
                ['error' => ['code' => 503, 'message' => 'The model is overloaded.']],
                'Gemini API Error: The model is overloaded.',
            ],
            'api error without message' => [
                ['error' => ['code' => 503]],
                'Gemini API Error: {"code":503}',
            ],
            'missing candidates' => [
                ['promptFeedback' => ['blockReason' => 'SAFETY']],
                'Gemini API returned no candidates. Response: {"promptFeedback":{"blockReason":"SAFETY"}}',
            ],
            'empty candidates' => [
                ['candidates' => []],
                'Gemini API returned no candidates.',
            ],
            'blocked candidate without content' => [
                ['candidates' => [['finishReason' => 'SAFETY']]],
                'Gemini API finished with reason: SAFETY.',
            ],
            'candidate without finish reason nor content' => [
                ['candidates' => [[]]],
                'Gemini API finished with reason: UNKNOWN.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('unusable_responses')]
    public function test_unusable_responses_raise_provider_exception(array $response, string $message): void
    {
        $provider = $this->provider($response);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        $provider->chat(new UserMessage('Hi'));
    }

    public function test_max_tokens_without_parts_returns_an_empty_message_with_the_stop_reason(): void
    {
        $provider = $this->provider(['candidates' => [['content' => ['role' => 'model'], 'finishReason' => 'MAX_TOKENS']]]);

        $message = $this->withWarningsAsExceptions(fn (): Message => $provider->chat(new UserMessage('Hi'))->message());

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame([], $message->getContentBlocks());
        $this->assertSame('MAX_TOKENS', $message->stopReason());
    }

    public function test_usage_metadata_is_mapped_with_optional_counters_defaulting_to_zero(): void
    {
        $provider = $this->provider(self::answer([['text' => 'ok']]) + ['usageMetadata' => ['promptTokenCount' => 12]]);

        $usage = $provider->chat(new UserMessage('Hi'))->message()->getUsage();

        $this->assertSame(12, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(0, $usage->reasoningTokens);
    }

    public function test_usage_metadata_maps_every_counter(): void
    {
        $provider = $this->provider(self::answer([['text' => 'ok']]) + ['usageMetadata' => [
            'promptTokenCount' => 12, 'candidatesTokenCount' => 5, 'cachedContentTokenCount' => 3, 'thoughtsTokenCount' => 7,
        ]]);

        $usage = $provider->chat(new UserMessage('Hi'))->message()->getUsage();

        $this->assertSame([12, 5, 3, 7], [$usage->inputTokens, $usage->outputTokens, $usage->cachedInputTokens, $usage->reasoningTokens]);
    }

    public function test_thought_parts_become_reasoning_blocks_and_signatures_are_kept(): void
    {
        $provider = $this->provider(self::answer([
            ['text' => 'Reasoning about it', 'thought' => true, 'thoughtSignature' => 'sig-thought'],
            ['text' => 'Final answer', 'thoughtSignature' => 'sig-text'],
            ['text' => 'Not a thought', 'thought' => false],
        ]));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $blocks = $message->getContentBlocks();
        $this->assertCount(3, $blocks);
        $this->assertInstanceOf(ReasoningContent::class, $blocks[0]);
        $this->assertSame('Reasoning about it', $blocks[0]->content);
        $this->assertSame('sig-thought', $blocks[0]->getMetadata('thought_signature'));
        $this->assertInstanceOf(TextContent::class, $blocks[1]);
        $this->assertNotInstanceOf(ReasoningContent::class, $blocks[1]);
        $this->assertSame('sig-text', $blocks[1]->getMetadata('thought_signature'));
        $this->assertSame(TextContent::class, $blocks[2]::class);
        $this->assertSame('Final answer Not a thought', $message->getContent());
    }

    public function test_inline_data_response_becomes_base64_image_block(): void
    {
        $provider = $this->provider(self::answer([
            ['text' => 'Your image'],
            ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => '/9j/4AAQ'], 'thoughtSignature' => 'sig-img'],
        ]));

        $message = $provider->chat(new UserMessage('Draw a cat'))->message();

        $image = $message->getImage();
        $this->assertInstanceOf(ImageContent::class, $image);
        $this->assertSame('/9j/4AAQ', $image->content);
        $this->assertSame(SourceType::BASE64, $image->sourceType);
        $this->assertSame('image/jpeg', $image->mediaType);
        $this->assertSame('sig-img', $image->getMetadata('thought_signature'));
        $this->assertSame('Your image', $message->getContent());
    }

    public function test_text_before_function_calls_is_kept_and_first_signature_moves_to_the_message(): void
    {
        $provider = $this->provider(self::answer([
            ['text' => 'Checking the weather.'],
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome']], 'thoughtSignature' => 'sig-call'],
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Oslo']]],
        ]));
        $provider->setTools([new ToolStub('get_weather', 'Weather lookup')]);

        $message = $provider->chat(new UserMessage('Weather?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking the weather.', $message->getContent());
        $this->assertSame('sig-call', $message->getMetadata('thought_signature'));
        $this->assertSame('STOP', $message->stopReason());
        $calls = $message->getToolCalls();
        $this->assertCount(2, $calls);
        $this->assertSame('Weather lookup', $calls[0]->getDescription());
        $this->assertSame([['city' => 'Rome'], ['city' => 'Oslo']], [$calls[0]->getInputs(), $calls[1]->getInputs()]);
    }

    public function test_function_call_for_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider(self::answer([['functionCall' => ['name' => 'code_execution', 'args' => []]]]));
        $provider->setTools([new ToolStub('get_weather')]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: code_execution.');

        $provider->chat(new UserMessage('Weather?'));
    }

    public function test_grounding_chunks_and_supports_become_citations(): void
    {
        $response = self::answer([['text' => 'Rome is the capital of Italy.']]);
        $response['candidates'][0]['groundingMetadata'] = [
            'groundingChunks' => [
                ['web' => ['uri' => 'https://example.com/rome', 'title' => 'Rome']],
                ['retrievedContext' => ['uri' => 'gs://bucket/doc']],
                ['web' => ['uri' => 'https://example.com/italy']],
            ],
            'groundingSupports' => [
                [
                    'segment' => ['startIndex' => 0, 'endIndex' => 28, 'text' => 'Rome is the capital of Italy'],
                    'groundingChunkIndices' => [2, 1, 7],
                    'confidenceScores' => [0.9, 0.5, 0.1],
                ],
            ],
        ];
        $provider = $this->provider($response);

        $citations = $provider->chat(new UserMessage('Capital?'))->message()->getMetadata('citations');

        $this->assertContainsOnlyInstancesOf(Citation::class, $citations);
        $this->assertCount(3, $citations);
        [$first, $second, $support] = $citations;
        $this->assertSame(['gemini_chunk_0', 'https://example.com/rome', 'Rome'], [$first->id, $first->source, $first->title]);
        $this->assertSame(['gemini_chunk_2', 'https://example.com/italy', null], [$second->id, $second->source, $second->title]);
        $this->assertStringStartsWith('gemini_support_', $support->id);
        $this->assertSame('https://example.com/italy', $support->source);
        $this->assertSame(0, $support->startIndex);
        $this->assertSame(28, $support->endIndex);
        $this->assertSame('Rome is the capital of Italy', $support->citedText);
        $this->assertSame(['chunk_index' => 2, 'confidence' => 0.9, 'provider' => 'gemini'], $support->metadata);
    }

    public function test_grounding_without_web_sources_adds_no_citations(): void
    {
        $response = self::answer([['text' => 'ok']]);
        $response['candidates'][0]['groundingMetadata'] = ['groundingChunks' => [['retrievedContext' => ['uri' => 'gs://x']]]];
        $provider = $this->provider($response);

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertNull($message->getMetadata('citations'));
    }
}
