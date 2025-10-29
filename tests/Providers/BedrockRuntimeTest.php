<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Aws\Result;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use GuzzleHttp\Promise\FulfilledPromise;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class BedrockRuntimeTest extends TestCase
{
    public function test_chat_request_basic_assistant_response(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $result = new Result([
            'usage' => [
                'inputTokens' => 5,
                'outputTokens' => 3,
            ],
            'output' => [
                'message' => [
                    'content' => [
                        ['text' => 'Hello'],
                        ['text' => ' world'],
                    ]
                ]
            ],
            'stopReason' => 'end_turn'
        ]);

        $capturedPayload = null;
        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->with($this->callback(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;
                return true;
            }))
            ->willReturn(new FulfilledPromise($result));

        $provider = new BedrockRuntime(
            $bedrockClient,
            'model-x',
            [
                'maxTokens' => 100,
                'temperature' => 0.5,
                'topP' => 0.9
            ],
        );

        $provider->systemPrompt('System prompt');

        $response = $provider->chat([
            new UserMessage('Hi')
        ]);

        $this->assertSame('Hello world', $response->getTextContent());
        $this->assertNotNull($response->getUsage());
        $this->assertSame(5, $response->getUsage()->jsonSerialize()['input_tokens']);
        $this->assertSame(3, $response->getUsage()->jsonSerialize()['output_tokens']);

        $this->assertIsArray($capturedPayload);
        $this->assertSame('model-x', $capturedPayload['modelId']);
        $this->assertArrayHasKey('messages', $capturedPayload);
        $this->assertSame([[ 'text' => 'System prompt' ]], $capturedPayload['system']);
        $this->assertSame([
            'maxTokens' => 100,
            'temperature' => 0.5,
            'topP' => 0.9,
        ], $capturedPayload['inferenceConfig']);
        $this->assertArrayNotHasKey('toolConfig', $capturedPayload, 'toolConfig should not be present when no tools set');
    }

    public function test_chat_request_tool_use_response(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $result = new Result([
            'usage' => [
                'inputTokens' => 7,
                'outputTokens' => 2,
            ],
            'output' => [
                'message' => [
                    'content' => [
                        [
                            'toolUse' => [
                                'name' => 'my_tool',
                                'toolUseId' => 'call-123',
                                'input' => '{"param":"value"}'
                            ]
                        ]
                    ]
                ]
            ],
            'stopReason' => 'tool_use'
        ]);

        $capturedPayload = null;
        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->with($this->callback(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;
                return true;
            }))
            ->willReturn(new FulfilledPromise($result));

        $tool = Tool::make('my_tool', 'Tool description')
            ->addProperty(new ToolProperty('param', PropertyType::STRING, 'Param description', true));

        $provider = (new BedrockRuntime(
            $bedrockClient,
            'model-y'
        ))->setTools([$tool]);

        $provider->systemPrompt('Sys');

        /** @var Message|ToolResultMessage $response */
        $response = $provider->chat([
            new UserMessage('Use tool')
        ]);

        /** @var ToolResultMessage $response */
        // Response should be a ToolCallMessage (subclass) with tools
        $this->assertSame('tool_call', $response->jsonSerialize()['type']);
        $this->assertCount(1, $response->getTools());
        $toolInstance = $response->getTools()[0];
        $this->assertSame('my_tool', $toolInstance->getName());
        $this->assertSame('call-123', $toolInstance->getCallId());
        $this->assertSame(['param' => 'value'], $toolInstance->getInputs());
        $this->assertSame(7, $response->getUsage()->jsonSerialize()['input_tokens']);
        $this->assertSame(2, $response->getUsage()->jsonSerialize()['output_tokens']);

        // Payload assertions
        $this->assertArrayHasKey('toolConfig', $capturedPayload);
        $this->assertArrayHasKey('tools', $capturedPayload['toolConfig']);
        $this->assertCount(1, $capturedPayload['toolConfig']['tools']);
        $toolSpec = $capturedPayload['toolConfig']['tools'][0]['toolSpec'];
        $this->assertSame('my_tool', $toolSpec['name']);
        $this->assertSame('Tool description', $toolSpec['description']);
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'param' => [
                    'type' => 'string',
                    'description' => 'Param description'
                ]
            ],
            'required' => ['param']
        ], $toolSpec['inputSchema']['json']);
    }

    public function test_tool_payload_without_properties_defaults_to_empty_object(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $result = new Result([
            'usage' => [
                'inputTokens' => 1,
                'outputTokens' => 1,
            ],
            'output' => [
                'message' => [
                    'content' => [ ['text' => 'Ok'] ]
                ]
            ],
            'stopReason' => 'end_turn'
        ]);

        $capturedPayload = null;
        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->with($this->callback(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;
                return true;
            }))
            ->willReturn(new FulfilledPromise($result));

        $tool = Tool::make('empty_tool', 'No props'); // no properties added

        $provider = (new BedrockRuntime(
            $bedrockClient,
            'model-z'
        ))->setTools([$tool]);

        $provider->chat([new UserMessage('Hi')]);

        $this->assertArrayHasKey('toolConfig', $capturedPayload);
        $toolSpec = $capturedPayload['toolConfig']['tools'][0]['toolSpec'];
        $this->assertSame('empty_tool', $toolSpec['name']);
        $this->assertSame('object', $toolSpec['inputSchema']['json']['type']);
        $this->assertSame([], $toolSpec['inputSchema']['json']['required']);
        $this->assertSame(\json_encode(new \stdClass()), \json_encode($toolSpec['inputSchema']['json']['properties']));
    }
}
