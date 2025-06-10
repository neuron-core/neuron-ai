<?php

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tests\stubs\Color;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

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

        $client = new Client(['handler' => $stack]);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setClient($client);

        $response = $provider->chat([new UserMessage('Hi')]);

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
                    'content' => 'Hi',
                ],
            ],
        ];

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('How can I assist you today?', $response->getContent());
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

        $client = new Client(['handler' => $stack]);
        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addAttachment(new Image(
                image: 'base64_encoded_image_data',
                type: AttachmentContentType::BASE64,
                mediaType: 'image/png'
            ));

        $provider->chat([$message]);

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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
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

        $client = new Client(['handler' => $stack]);
        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addAttachment(new Image(image: 'https://example.com/image.png'));

        $provider->chat([$message]);

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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
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

        $client = new Client(['handler' => $stack]);
        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setClient($client);

        $message = (new UserMessage('Describe this document'))
            ->addAttachment(new Document(
                document: 'base64_encoded_document_data',
                type: AttachmentContentType::BASE64,
                mediaType: 'pdf'
            ));

        $provider->chat([$message]);

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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
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

        $client = new Client(['handler' => $stack]);
        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setClient($client);

        $message = (new UserMessage('Describe this document'))
            ->addAttachment(new Document(document: 'https://example.com/document.pdf'));

        $provider->chat([$message]);

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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload()
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

        $client = new Client(['handler' => $stack]);
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
            ->setClient($client);

        $provider->chat([new UserMessage('Hi')]);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hi',
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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_object_properties()
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

        $client = new Client(['handler' => $stack]);
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
            ->setClient($client);

        $provider->chat([new UserMessage('Hi')]);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hi',
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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_object_mapped_class()
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

        $client = new Client(['handler' => $stack]);
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
            ->setClient($client);

        $provider->chat([new UserMessage('Hi')]);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hi',
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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
    }

    public function test_tools_payload_with_object_array_properties()
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

        $client = new Client(['handler' => $stack]);
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
            ->setClient($client);

        $provider->chat([new UserMessage('Hi')]);

        $request = $sentRequests[0];

        $expectedResponse = [
            'model' => 'claude-3-7-sonnet-latest',
            'max_tokens' => 8192,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hi',
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

        $this->assertSame($expectedResponse, json_decode($request['request']->getBody()->getContents(), true));
    }
}
