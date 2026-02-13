<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Attachments\Document;
use NeuronAI\Chat\Attachments\Image;
use NeuronAI\Chat\Enums\AttachmentContentType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

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

    protected string $bodyWithAttachment = '{
	"candidates": [
		{
			"content": {
			    "role": "model",
			    "parts": [
                    {
                        "inlineData": {
                            "mimeType": "image/png",
                            "data": "abc123"
                        }
                    },
                    {
                        "inlineData": {
                            "mimeType": "application/pdf",
                            "data": "321cba"
                        }
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

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $response = $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Hi']
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
        $this->assertInstanceOf(AssistantMessage::class, $response);
        $this->assertSame('STOP', $response->stopReason());
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

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addAttachment(new Image(
                image: '/test.png',
                mediaType: 'image/png'
            ));

        $response = $provider->chat([$message]);

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

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addAttachment(new Image(
                image: 'base64_encoded_image_data',
                type: AttachmentContentType::BASE64,
                mediaType: 'image/png'
            ));

        $response = $provider->chat([$message]);

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
        $this->assertSame('test response', $response->getContent());
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

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $message = (new UserMessage('Describe this document'))
            ->addAttachment(new Document(
                document: '/test.pdf',
                mediaType: 'application/pdf'
            ));

        $response = $provider->chat([$message]);

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

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $message = (new UserMessage('Describe this document'))
            ->addAttachment(new Image(
                image: 'base64_encoded_document_data',
                type: AttachmentContentType::BASE64,
                mediaType: 'application/pdf'
            ));

        $response = $provider->chat([$message]);

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

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))
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

    public function test_chat_with_attachment_response(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->bodyWithAttachment),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.5-flash-image'))->setClient($client);

        $message = (new UserMessage('Generate two files'));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Generate two files'],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('', $response->getContent()); // We did this on purpose, depending on your responseModalities it might not generate any text
        $this->assertCount(2, $attachmentList = $response->getAttachments());

        $this->assertSame('image/png', $attachmentList[0]->mediaType);
        $this->assertSame('abc123', $attachmentList[0]->content);

        $this->assertSame('application/pdf', $attachmentList[1]->mediaType);
        $this->assertSame('321cba', $attachmentList[1]->content);
    }

    public function test_function_call_in_first_part(): void
    {
        $body = json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['functionCall' => ['name' => 'my_tool', 'args' => ['prop' => 'hello']]],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);

        $mockHandler = new MockHandler([new Response(status: 200, body: $body)]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))
            ->setTools([$this->makeSimpleTool('my_tool')])
            ->setClient($client);

        $response = $provider->chat([new UserMessage('test')]);

        $this->assertInstanceOf(ToolCallMessage::class, $response);
        $this->assertCount(1, $response->getTools());
        $this->assertSame('my_tool', $response->getTools()[0]->getName());
    }

    public function test_function_call_after_text_part(): void
    {
        $body = json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Let me call the tool.'],
                        ['functionCall' => ['name' => 'my_tool', 'args' => ['prop' => 'data']]],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);

        $mockHandler = new MockHandler([new Response(status: 200, body: $body)]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $provider = (new Gemini('', 'gemini-3-pro-preview'))
            ->setTools([$this->makeSimpleTool('my_tool')])
            ->setClient($client);

        $response = $provider->chat([new UserMessage('test')]);

        $this->assertInstanceOf(ToolCallMessage::class, $response);
        $this->assertCount(1, $response->getTools());
        $this->assertSame('my_tool', $response->getTools()[0]->getName());
    }

    public function test_multiple_function_calls_after_text_part(): void
    {
        $body = json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'I will call both tools.'],
                        ['functionCall' => ['name' => 'tool_a', 'args' => ['prop' => '1']]],
                        ['functionCall' => ['name' => 'tool_b', 'args' => ['prop' => '2']]],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);

        $mockHandler = new MockHandler([new Response(status: 200, body: $body)]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $provider = (new Gemini('', 'gemini-3-pro-preview'))
            ->setTools([
                $this->makeSimpleTool('tool_a'),
                $this->makeSimpleTool('tool_b'),
            ])
            ->setClient($client);

        $response = $provider->chat([new UserMessage('test')]);

        $this->assertInstanceOf(ToolCallMessage::class, $response);
        $this->assertCount(2, $response->getTools());
        $this->assertSame('tool_a', $response->getTools()[0]->getName());
        $this->assertSame('tool_b', $response->getTools()[1]->getName());
    }

    public function test_no_function_call_returns_assistant_message(): void
    {
        $body = json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Here is a plain text response.'],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);

        $mockHandler = new MockHandler([new Response(status: 200, body: $body)]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $provider = (new Gemini('', 'gemini-3-pro-preview'))
            ->setTools([$this->makeSimpleTool('my_tool')])
            ->setClient($client);

        $response = $provider->chat([new UserMessage('test')]);

        $this->assertInstanceOf(AssistantMessage::class, $response);
        $this->assertNotInstanceOf(ToolCallMessage::class, $response);
        $this->assertSame('Here is a plain text response.', $response->getContent());
    }

    public function test_function_call_with_thought_signature(): void
    {
        $body = json_encode([
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['text' => 'Thinking...'],
                        [
                            'functionCall' => ['name' => 'my_tool', 'args' => ['prop' => 'value']],
                            'thoughtSignature' => 'sig-abc123',
                        ],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ]);

        $mockHandler = new MockHandler([new Response(status: 200, body: $body)]);
        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $provider = (new Gemini('', 'gemini-3-pro-preview'))
            ->setTools([$this->makeSimpleTool('my_tool')])
            ->setClient($client);

        $response = $provider->chat([new UserMessage('test')]);

        $this->assertInstanceOf(ToolCallMessage::class, $response);
        $this->assertSame('sig-abc123', $response->getMetadata('thoughtSignature'));
    }

    private function makeSimpleTool(string $name): ToolInterface
    {
        return Tool::make($name, "A test tool called {$name}.")
            ->addProperty(new ToolProperty('prop', PropertyType::STRING, 'A test property', true))
            ->setCallable(static fn (string $prop): string => "result: {$prop}");
    }
}
