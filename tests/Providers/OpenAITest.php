<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Stubs\StructuredOutput\Color;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function count;
use function json_decode;

class OpenAITest extends TestCase
{
    protected string $body = '{"model": "gpt-4o","choices":[{"index": 0,"finish_reason": "stop","message": {"role": "assistant","content": "test response"}}],"usage": {"prompt_tokens": 19,"completion_tokens": 10,"total_tokens": 29}}';

    public function test_chat_request(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new OpenAI('', 'gpt-4o'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Hi',
                        ]
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
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

        $provider = (new OpenAI('', 'gpt-4o'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this image'))
            ->addContent(new ImageContent(content: 'https://example.com/image.png', sourceType: SourceType::URL));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe this image'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
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

        $provider = (new OpenAI('', 'gpt-4o'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this image'))
            ->addContent(new ImageContent(content: 'base_64_encoded_image', sourceType: SourceType::BASE64, mediaType: 'image/jpeg'));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe this image'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,base_64_encoded_image']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
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

        $provider = (new OpenAI('', 'gpt-4o'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $message = (new UserMessage('Describe this document'))
            ->addContent(new ImageContent(content: 'base_64_encoded_document', sourceType: SourceType::BASE64, mediaType: 'application/pdf'));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe this document'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:application/pdf;base64,base_64_encoded_document']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
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

        $provider = (new OpenAI('', 'gpt-4o'))
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

        $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
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
                    'type' => 'function',
                    'function' => [
                        'name' => 'tool',
                        'description' => 'description',
                        'parameters' => [
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
            ]
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_array_properties(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new OpenAI('', 'gpt-4o'))
            ->setTools([
                Tool::make('tool', 'description')
                    ->addProperty(
                        new ArrayProperty(
                            'array_prop',
                            'description',
                            false,
                            new ToolProperty(
                                'simple_prop',
                                PropertyType::STRING,
                                'description',
                            )
                        )
                    )
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
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
                    'type' => 'function',
                    'function' => [
                        'name' => 'tool',
                        'description' => 'description',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'array_prop' => [
                                    'type' => 'array',
                                    'description' => 'description',
                                    'items' => [
                                        'type' => 'string',
                                        'description' => 'description',
                                    ]
                                ]
                            ],
                            'required' => [],
                        ]
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_array_properties_no_items(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new OpenAI('', 'gpt-4o'))
            ->setTools([
                Tool::make('tool', 'description')
                    ->addProperty(
                        new ArrayProperty(
                            'array_prop',
                            'description',
                            false
                        )
                    )
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
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
                    'type' => 'function',
                    'function' => [
                        'name' => 'tool',
                        'description' => 'description',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'array_prop' => [
                                    'type' => 'array',
                                    'description' => 'description',
                                    'items' => [
                                        'type' => 'string',
                                    ]
                                ]
                            ],
                            'required' => [],
                        ]
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
    }


    public function test_tools_payload_with_array_object_mapped(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new OpenAI('', 'gpt-4o'))
            ->setTools([
                Tool::make('tool', 'description')
                    ->addProperty(
                        new ArrayProperty(
                            'array_prop',
                            'description',
                            true,
                            new ObjectProperty(
                                'color',
                                'Description for color',
                                true,
                                Color::class
                            )
                        )
                    )
            ])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
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
                    'type' => 'function',
                    'function' => [
                        'name' => 'tool',
                        'description' => 'description',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'array_prop' => [
                                    'type' => 'array',
                                    'description' => 'description',
                                    'items' => [
                                        'type' => 'object',
                                        'description' => 'Description for color',
                                        'properties' => [
                                            "r" => [
                                                'type' => 'number',
                                                'description' => 'The RED',
                                            ],
                                            "g" => [
                                                'type' => 'number',
                                                'description' => 'The GREEN',
                                            ],
                                            "b" => [
                                                'type' => 'number',
                                                'description' => 'The BLUE',
                                            ]
                                        ],
                                        "required" => ["r", "g", "b"]
                                    ]
                                ]
                            ],
                            'required' => ["array_prop"],
                        ]
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
    }

    public function test_stream_returns_message_with_text_chunks(): void
    {
        // Mock SSE streaming response from OpenAI
        $streamBody = "data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"\"},\"finish_reason\":null}]}\n\n";
        $streamBody .= "data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n";
        $streamBody .= "data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\" there\"},\"finish_reason\":null}]}\n\n";
        $streamBody .= "data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"!\"},\"finish_reason\":\"stop\"}]}\n\n";
        $streamBody .= "data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[],\"usage\":{\"prompt_tokens\":8,\"completion_tokens\":3}}\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);
        $stack = HandlerStack::create($mockHandler);

        $provider = (new OpenAI('', 'gpt-4o'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $generator = $provider->stream([new UserMessage('Hi')]);

        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        // Get the final message from generator return value
        $message = $generator->getReturn();

        // Assert we received TextChunk instances (empty strings filtered out)
        $this->assertGreaterThanOrEqual(3, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertInstanceOf(TextChunk::class, $chunk);
        }

        // Verify chunk contents
        $this->assertSame('Hello', $chunks[0]->content);
        $this->assertSame(' there', $chunks[1]->content);
        $this->assertSame('!', $chunks[2]->content);

        // Assert the final message is correct
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Hello there!', $message->getContent());
        $this->assertSame(8, $message->getUsage()->inputTokens);
        $this->assertSame(3, $message->getUsage()->outputTokens);
    }
}
