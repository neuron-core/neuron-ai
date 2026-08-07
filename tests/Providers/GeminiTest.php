<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Tests\Stubs\Tools\ToolStub;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;

class GeminiTest extends TestCase
{
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
        $this->assertInstanceOf(\NeuronAI\Chat\Messages\AssistantMessage::class, $response->message());

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

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
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

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
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

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
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

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
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
            ->addContent(new ImageContent(
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

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
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

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
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
}
