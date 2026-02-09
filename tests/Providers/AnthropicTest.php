<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tests\Stubs\StructuredOutput\Color;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;

class AnthropicTest extends TestCase
{
    public function test_chat_request(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "How can I assist you today?"}],"usage": {"input_tokens": 19,"output_tokens": 29}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hi'],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('How can I assist you today?', $response->getContent());
        $this->assertSame('end_turn', $response->stopReason());
    }

    public function test_chat_with_base64_image(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this image'))
            ->addContent(new ImageContent(
                content: 'base64_encoded_image_data',
                sourceType: SourceType::BASE64,
                mediaType: 'image/png'
            ));

        $provider->chat($message);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Describe this image',
                        ],
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'image/png',
                                'data' => 'base64_encoded_image_data',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_chat_with_url_image(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this image'))
            ->addContent(new ImageContent(content: 'https://example.com/image.png', sourceType: SourceType::URL));

        $provider->chat($message);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Describe this image',
                        ],
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'url',
                                'url' => 'https://example.com/image.png',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_chat_with_base64_document(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this document'))
            ->addContent(new FileContent(
                content: 'base64_encoded_document_data',
                sourceType: SourceType::BASE64,
                mediaType: 'pdf'
            ));

        $provider->chat($message);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Describe this document',
                        ],
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => 'pdf',
                                'data' => 'base64_encoded_document_data',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_chat_with_url_document(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this document'))
            ->addContent(new FileContent(content: 'https://example.com/document.pdf', sourceType: SourceType::URL));

        $provider->chat($message);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Describe this document',
                        ],
                        [
                            'type' => 'document',
                            'source' => [
                                'type' => 'url',
                                'url' => 'https://example.com/document.pdf',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);


        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setTools([
                Tool::make('tool', 'description')
                    ->addProperty(
                        new ToolProperty(
                            'prop',
                            PropertyType::STRING,
                            'description',
                            true
                        )
                    )
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat(new UserMessage('Hi'));

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hi'],
                    ],
                ],
            ],
            'tools' => [
                [
                    'name' => 'tool',
                    'description' => 'description',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'prop' => [
                                'type' => 'string',
                                'description' => 'description',
                            ]
                        ],
                        'required' => ['prop'],
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_object_properties(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);


        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setTools([
                Tool::make('tool', 'description')
                    ->addProperty(
                        new ObjectProperty(
                            name: 'obj_prop',
                            description: 'description',
                            required: false,
                            properties: [
                                new ToolProperty(
                                    'simple_prop',
                                    PropertyType::STRING,
                                    'description',
                                    true
                                )
                            ]
                        )
                    )
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat(new UserMessage('Hi'));

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hi'],
                    ],
                ],
            ],
            'tools' => [
                [
                    'name' => 'tool',
                    'description' => 'description',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'obj_prop' => [
                                'type' => 'object',
                                'description' => 'description',
                                'properties' => [
                                    'simple_prop' => [
                                        'type' => 'string',
                                        'description' => 'description',
                                    ]
                                ],
                                'required' => ['simple_prop']
                            ]
                        ],
                        'required' => [],
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_object_mapped_class(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);


        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setTools([
                Tool::make('tool', 'description')
                    ->addProperty(
                        new ObjectProperty(
                            name: 'color',
                            description: 'Description for color',
                            required: true,
                            class: Color::class
                        )
                    )
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat(new UserMessage('Hi'));

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hi'],
                    ],
                ],
            ],
            'tools' => [
                [
                    'name' => 'tool',
                    'description' => 'description',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'color' => [
                                'type' => 'object',
                                'description' => 'Description for color',
                                'properties' => [
                                    'r' => [
                                        'type' => 'number',
                                        'description' => 'The RED',
                                    ],
                                    'g' => [
                                        'type' => 'number',
                                        'description' => 'The GREEN',
                                    ],
                                    'b' => [
                                        'type' => 'number',
                                        'description' => 'The BLUE',
                                    ]
                                ],
                                'required' => ["r", "g", "b"],
                            ]
                        ],
                        'required' => ["color"],
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_object_array_properties(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model": "claude-3-7-sonnet-latest","role": "assistant","stop_reason": "end_turn","content":[{"type": "text","text": "Understood."}],"usage": {"input_tokens": 50,"output_tokens": 10}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);


        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setTools([
                Tool::make('tool', 'description')
                    ->addProperty(
                        new ArrayProperty(
                            'array_prop',
                            'description for array_prop',
                            true,
                            new ObjectProperty(
                                name: 'obj_prop',
                                description: 'description for obj_prop',
                                required: true,
                                properties: [
                                    new ToolProperty(
                                        'simple_prop_a',
                                        PropertyType::STRING,
                                        'description for a',
                                        true
                                    ),
                                    new ToolProperty(
                                        'simple_prop_b',
                                        PropertyType::INTEGER,
                                        'description for b',
                                        false
                                    ),
                                    new ToolProperty(
                                        'simple_prop_c',
                                        PropertyType::NUMBER,
                                        'description for c',
                                    ),
                                ]
                            )
                        )
                    )
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat(new UserMessage('Hi'));

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hi'],
                    ],
                ],
            ],
            'tools' => [
                [
                    'name' => 'tool',
                    'description' => 'description',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'array_prop' => [
                                'type' => 'array',
                                'description' => 'description for array_prop',
                                'items' => [
                                    'type' => 'object',
                                    'description' => 'description for obj_prop',
                                    'properties' => [
                                        'simple_prop_a' => [
                                            'type' => 'string',
                                            'description' => 'description for a',
                                        ],
                                        'simple_prop_b' => [
                                            'type' => 'integer',
                                            'description' => 'description for b',
                                        ],
                                        'simple_prop_c' => [
                                            'type' => 'number',
                                            'description' => 'description for c',
                                        ]
                                    ],
                                    'required' => ['simple_prop_a']
                                ],
                            ]
                        ],
                        'required' => ['array_prop'],
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedResponse, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_stream_returns_message_with_text_chunks(): void
    {
        // Mock SSE streaming response with text content
        $streamBody = "event: message_start\n";
        $streamBody .= "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_123\",\"usage\":{\"input_tokens\":10,\"output_tokens\":0}}}\n\n";
        $streamBody .= "event: content_block_start\n";
        $streamBody .= "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\" world\"}}\n\n";
        $streamBody .= "event: content_block_stop\n";
        $streamBody .= "data: {\"type\":\"content_block_stop\",\"index\":0}\n\n";
        $streamBody .= "event: message_delta\n";
        $streamBody .= "data: {\"type\":\"message_delta\",\"usage\":{\"output_tokens\":5}}\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $generator = $provider->stream(new UserMessage('Hi'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        // Get the final message from the generator return value
        $message = $generator->getReturn();

        // Assert we received TextChunk instances
        $this->assertCount(2, $chunks);
        $this->assertInstanceOf(TextChunk::class, $chunks[0]);
        $this->assertInstanceOf(TextChunk::class, $chunks[1]);
        $this->assertSame('Hello', $chunks[0]->content);
        $this->assertSame(' world', $chunks[1]->content);

        // Assert the final message is correct
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Hello world', $message->getContent());
        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(5, $message->getUsage()->outputTokens);
    }

    public function test_stream_returns_message_with_thinking_and_text_chunks(): void
    {
        // Mock SSE streaming response with thinking and text content
        $streamBody = "event: message_start\n";
        $streamBody .= "data: {\"type\":\"message_start\",\"message\":{\"id\":\"msg_123\",\"usage\":{\"input_tokens\":15,\"output_tokens\":0}}}\n\n";
        $streamBody .= "event: content_block_start\n";
        $streamBody .= "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"thinking\",\"thinking\":\"\"}}\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"thinking_delta\",\"thinking\":\"Let me think\"}}\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"thinking_delta\",\"thinking\":\" about this\"}}\n\n";
        $streamBody .= "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"signature_delta\",\"signature\":\"sig123\"}}\n\n";
        $streamBody .= "event: content_block_stop\n";
        $streamBody .= "data: {\"type\":\"content_block_stop\",\"index\":0}\n\n";
        $streamBody .= "event: content_block_start\n";
        $streamBody .= "data: {\"type\":\"content_block_start\",\"index\":1,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= "data: {\"type\":\"content_block_delta\",\"index\":1,\"delta\":{\"type\":\"text_delta\",\"text\":\"The answer\"}}\n\n";
        $streamBody .= "event: content_block_stop\n";
        $streamBody .= "data: {\"type\":\"content_block_stop\",\"index\":1}\n\n";
        $streamBody .= "event: message_delta\n";
        $streamBody .= "data: {\"type\":\"message_delta\",\"usage\":{\"output_tokens\":8}}\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $generator = $provider->stream(new UserMessage('Question?'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        // Get the final message from generator return value
        $message = $generator->getReturn();

        // Assert we received both ReasoningChunk and TextChunk instances
        $this->assertCount(3, $chunks);
        $this->assertInstanceOf(ReasoningChunk::class, $chunks[0]);
        $this->assertInstanceOf(ReasoningChunk::class, $chunks[1]);
        $this->assertInstanceOf(TextChunk::class, $chunks[2]);
        $this->assertSame('Let me think', $chunks[0]->content);
        $this->assertSame(' about this', $chunks[1]->content);
        $this->assertSame('The answer', $chunks[2]->content);

        // Assert the final message has both thinking and text content blocks
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $contentBlocks = $message->getContentBlocks();
        $this->assertCount(2, $contentBlocks);
        $this->assertInstanceOf(ReasoningContent::class, $contentBlocks[0]);
        $this->assertInstanceOf(TextContent::class, $contentBlocks[1]);
        $this->assertSame('Let me think about this', $contentBlocks[0]->content);
        $this->assertSame('sig123', $contentBlocks[0]->id);
        $this->assertSame('The answer', $contentBlocks[1]->content);
        $this->assertSame(15, $message->getUsage()->inputTokens);
        $this->assertSame(8, $message->getUsage()->outputTokens);
    }
}
