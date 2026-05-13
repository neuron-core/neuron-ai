<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\AWS\MessageMapper;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;
use stdClass;

use function base64_encode;
use function json_encode;

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
                    ],
                ],
            ],
            'stopReason' => 'end_turn',
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
                'topP' => 0.9,
            ],
        );

        $provider->systemPrompt('System prompt');

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response);

        $this->assertSame('Hello world', $response->getContent());
        $this->assertSame('end_turn', $response->stopReason());
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
                                'input' => '{"param":"value"}',
                            ],
                        ],
                    ],
                ],
            ],
            'stopReason' => 'tool_use',
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

        $response = $provider->chat(new UserMessage('Use tool'));
        $this->assertInstanceOf(ToolCallMessage::class, $response);

        // Response should be a ToolCallMessage (subclass) with tools
        $this->assertSame('tool_call', $response->jsonSerialize()['type']);
        $this->assertCount(1, $response->getTools());
        $toolInstance = $response->getTools()[0];
        $this->assertSame('my_tool', $toolInstance->getName());
        $this->assertSame('call-123', $toolInstance->getCallId());
        $this->assertSame(['param' => 'value'], $toolInstance->getInputs());
        $this->assertSame('tool_use', $response->stopReason());
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
                    'description' => 'Param description',
                ],
            ],
            'required' => ['param'],
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
                    'content' => [ ['text' => 'Ok'] ],
                ],
            ],
            'stopReason' => 'end_turn',
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

        $provider->chat(new UserMessage('Hi'));

        $this->assertArrayHasKey('toolConfig', $capturedPayload);
        $toolSpec = $capturedPayload['toolConfig']['tools'][0]['toolSpec'];
        $this->assertSame('empty_tool', $toolSpec['name']);
        $this->assertSame('object', $toolSpec['inputSchema']['json']['type']);
        $this->assertSame([], $toolSpec['inputSchema']['json']['required']);
        $this->assertSame(json_encode(new stdClass()), json_encode($toolSpec['inputSchema']['json']['properties']));
    }

    public function test_tool_call_with_empty_input_serializes_as_json_object(): void
    {
        $tool = Tool::make('noop', 'no params');
        $tool->setCallId('call-empty');
        $tool->setInputs([]);

        $message = new ToolCallMessage(null, [$tool]);

        $mapped = (new MessageMapper())->map([$message]);

        $toolUse = $mapped[0]['content'][0]['toolUse'];
        $this->assertSame('noop', $toolUse['name']);
        $this->assertSame('call-empty', $toolUse['toolUseId']);
        // AWS Converse rejects '[]' for input; must be a JSON object '{}'.
        $this->assertSame('{}', json_encode($toolUse['input']));
    }

    public function test_tool_call_with_inputs_passes_through_unchanged(): void
    {
        $tool = Tool::make('search', 'search the web');
        $tool->setCallId('call-1');
        $tool->setInputs(['query' => 'php']);

        $message = new ToolCallMessage(null, [$tool]);

        $mapped = (new MessageMapper())->map([$message]);

        $this->assertSame(
            ['query' => 'php'],
            $mapped[0]['content'][0]['toolUse']['input'],
        );
    }

    public function test_chat_request_with_base64_image(): void
    {
        $rawBytes = "\xff\xd8\xff\xe0\x00\x10JFIFbinaryjpegdata";
        $base64 = base64_encode($rawBytes);

        $message = new UserMessage([
            new TextContent('Describe this image'),
            new ImageContent($base64, SourceType::BASE64, 'image/jpeg'),
        ]);

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        $content = $capturedPayload['messages'][0]['content'];
        $this->assertCount(2, $content);
        $this->assertSame(['text' => 'Describe this image'], $content[0]);
        $this->assertSame([
            'image' => [
                'format' => 'jpeg',
                'source' => ['bytes' => $rawBytes],
            ],
        ], $content[1]);
    }

    public function test_chat_request_with_s3_image(): void
    {
        $message = new UserMessage(new ImageContent(
            's3://my-bucket/path/to/image.png',
            SourceType::ID,
            'image/png',
        ));

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        $this->assertSame([
            'image' => [
                'format' => 'png',
                'source' => ['s3Location' => ['uri' => 's3://my-bucket/path/to/image.png']],
            ],
        ], $capturedPayload['messages'][0]['content'][0]);
    }

    public function test_chat_request_with_url_image_is_dropped(): void
    {
        $message = new UserMessage([
            new TextContent('Hi'),
            new ImageContent('https://example.com/image.jpg', SourceType::URL, 'image/jpeg'),
        ]);

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        $content = $capturedPayload['messages'][0]['content'];
        $this->assertCount(1, $content);
        $this->assertSame(['text' => 'Hi'], $content[0]);
    }

    public function test_chat_request_with_pdf_document(): void
    {
        $rawBytes = "%PDF-1.4\nfake pdf bytes";
        $base64 = base64_encode($rawBytes);

        $message = new UserMessage(new FileContent(
            $base64,
            SourceType::BASE64,
            'application/pdf',
            'report.pdf',
        ));

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        // AWS Converse rule strips '.' from filenames -> 'report.pdf' becomes 'report-pdf'.
        $this->assertSame([
            'document' => [
                'format' => 'pdf',
                'name' => 'report-pdf',
                'source' => ['bytes' => $rawBytes],
            ],
        ], $capturedPayload['messages'][0]['content'][0]);
    }

    public function test_chat_request_with_pdf_document_without_filename_generates_name(): void
    {
        $rawBytes = '%PDF-1.4 anon';
        $message = new UserMessage(new FileContent(
            base64_encode($rawBytes),
            SourceType::BASE64,
            'application/pdf',
        ));

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        $document = $capturedPayload['messages'][0]['content'][0]['document'];
        $this->assertSame('pdf', $document['format']);
        $this->assertSame(['bytes' => $rawBytes], $document['source']);
        $this->assertNotEmpty($document['name'], 'AWS Converse requires document.name to be present');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9\s\-()\[\]]+$/', $document['name']);
    }

    public function test_chat_request_with_audio(): void
    {
        $rawBytes = "fakeaudiopayload";
        $base64 = base64_encode($rawBytes);

        $message = new UserMessage(new AudioContent($base64, SourceType::BASE64, 'audio/mp3'));

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        $this->assertSame([
            'audio' => [
                'format' => 'mp3',
                'source' => ['bytes' => $rawBytes],
            ],
        ], $capturedPayload['messages'][0]['content'][0]);
    }

    public function test_chat_request_with_video(): void
    {
        $rawBytes = "fakevideopayload";
        $base64 = base64_encode($rawBytes);

        $message = new UserMessage(new VideoContent($base64, SourceType::BASE64, 'video/mp4'));

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        $this->assertSame([
            'video' => [
                'format' => 'mp4',
                'source' => ['bytes' => $rawBytes],
            ],
        ], $capturedPayload['messages'][0]['content'][0]);
    }

    public function test_chat_request_with_mixed_content(): void
    {
        $imageBytes = "\xff\xd8\xffimg";
        $docBytes = "%PDF-1.4doc";

        $message = new UserMessage([
            new TextContent('Compare these'),
            new ImageContent(base64_encode($imageBytes), SourceType::BASE64, 'image/jpeg'),
            new FileContent(base64_encode($docBytes), SourceType::BASE64, 'application/pdf', 'a.pdf'),
        ]);

        $capturedPayload = $this->dispatchAndCapturePayload($message);

        $content = $capturedPayload['messages'][0]['content'];
        $this->assertCount(3, $content);
        $this->assertSame(['text' => 'Compare these'], $content[0]);
        $this->assertArrayHasKey('image', $content[1]);
        $this->assertSame($imageBytes, $content[1]['image']['source']['bytes']);
        $this->assertArrayHasKey('document', $content[2]);
        $this->assertSame('a-pdf', $content[2]['document']['name']);
        $this->assertSame($docBytes, $content[2]['document']['source']['bytes']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dispatchAndCapturePayload(UserMessage $message): array
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $result = new Result([
            'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
            'output' => ['message' => ['content' => [['text' => 'ok']]]],
            'stopReason' => 'end_turn',
        ]);

        $capturedPayload = null;
        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->with($this->callback(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;
                return true;
            }))
            ->willReturn(new FulfilledPromise($result));

        $provider = new BedrockRuntime($bedrockClient, 'model-media');
        $provider->chat($message);

        return $capturedPayload;
    }
}
