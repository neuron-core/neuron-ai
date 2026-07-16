<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
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
        $this->assertInstanceOf(\NeuronAI\Chat\Messages\AssistantMessage::class, $response);

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
        $this->assertSame('test response', $response->getContent());
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

    public function test_structured_with_supported_model(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        // gemini-2.5-flash is a supported model (not in unsupportedModels)
        $provider = (new Gemini('', 'gemini-2.5-flash'))
            ->setTools([
                Tool::make('tool', 'description')
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

        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'User name',
                ],
            ],
            'required' => ['name'],
        ];

        $provider->structured(new UserMessage('hi'), 'SomeClass', $schema);

        $this->assertCount(1, $sentRequests);
        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody()->getContents(), true);

        // Ensure generationConfig has responseSchema and responseMimeType
        $this->assertArrayHasKey('generationConfig', $requestBody);
        $this->assertSame('application/json', $requestBody['generationConfig']['responseMimeType']);
        $this->assertArrayHasKey('responseSchema', $requestBody['generationConfig']);

        // Check adapted schema structure
        $this->assertSame('object', $requestBody['generationConfig']['responseSchema']['type']);

        // Ensure user message is not modified
        $this->assertSame('hi', $requestBody['contents'][0]['parts'][0]['text']);
    }

    public function test_structured_schema_with_property_named_type(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new Gemini('', 'gemini-2.5-flash'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        // Schema with a property literally named "type" — this used to corrupt
        // the properties map because adaptSchema treated it as a type union.
        $schema = [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'description' => 'Call type identifier',
                    'type' => 'string',
                ],
                'name' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['type', 'name'],
        ];

        $provider->structured(new UserMessage('hi'), 'SomeClass', $schema);

        $this->assertCount(1, $sentRequests);
        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody()->getContents(), true);

        $responseSchema = $requestBody['generationConfig']['responseSchema'];

        // The "type" property must remain a schema object, not be collapsed
        // to the description string.
        $this->assertIsArray($responseSchema['properties']['type']);
        $this->assertSame('string', $responseSchema['properties']['type']['type']);
        $this->assertSame('Call type identifier', $responseSchema['properties']['type']['description']);
        $this->assertSame('string', $responseSchema['properties']['name']['type']);
    }

    public function test_structured_with_unsupported_model(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        // gemini-1.5-flash is unsupported (in unsupportedModels)
        $provider = (new Gemini('', 'gemini-1.5-flash'))
            ->setTools([
                Tool::make('tool', 'description')
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

        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'User name',
                ],
            ],
            'required' => ['name'],
        ];

        $provider->structured(new UserMessage('hi'), 'SomeClass', $schema);

        $this->assertCount(1, $sentRequests);
        $requestBody = json_decode((string) $sentRequests[0]['request']->getBody()->getContents(), true);

        // Ensure generationConfig does NOT have responseSchema (since fallback is used)
        if (isset($requestBody['generationConfig'])) {
            $this->assertArrayNotHasKey('responseSchema', $requestBody['generationConfig']);
        }

        // Ensure user message is appended with the JSON schema instruction
        $expectedText = 'hi Respond using this JSON schema: {"type":"object","properties":{"name":{"type":"string","description":"User name"}},"required":["name"]}';
        $this->assertSame($expectedText, $requestBody['contents'][0]['parts'][0]['text']);
    }
}
