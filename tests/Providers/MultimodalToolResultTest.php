<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function base64_encode;

class MultimodalToolResultTest extends TestCase
{
    protected function multimodalTool(string $imageData = 'imgdata'): ToolCall
    {
        return ToolCall::make('get_chart', description: 'Render a chart')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([
                new TextContent('caption'),
                new ImageContent($imageData, SourceType::BASE64, 'image/png'),
            ]));
    }

    protected function stringTool(): ToolCall
    {
        return ToolCall::make('get_price', description: 'Get a price')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult('plain result');
    }

    public function test_anthropic_maps_tool_output_as_native_blocks(): void
    {
        $mapper = new \NeuronAI\Providers\Anthropic\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->multimodalTool()])]);

        $content = $payload[0]['content'][0]['content'];
        $this->assertSame([
            ['type' => 'text', 'text' => 'caption'],
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/png',
                    'data' => 'imgdata',
                ],
            ],
        ], $content);
    }

    public function test_anthropic_string_result_unchanged(): void
    {
        $mapper = new \NeuronAI\Providers\Anthropic\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->stringTool()])]);

        $this->assertSame('plain result', $payload[0]['content'][0]['content']);
    }

    public function test_bedrock_maps_tool_output_as_native_blocks(): void
    {
        $mapper = new \NeuronAI\Providers\AWS\MessageMapper();

        $imageData = base64_encode('raw-image-bytes');
        $payload = $mapper->map([new ToolResultMessage([$this->multimodalTool($imageData)])]);

        $content = $payload[0]['content'][0]['toolResult']['content'];
        $this->assertSame([
            ['text' => 'caption'],
            [
                'image' => [
                    'format' => 'png',
                    'source' => ['bytes' => base64_decode($imageData)],
                ],
            ],
        ], $content);
    }

    public function test_bedrock_string_result_unchanged(): void
    {
        $mapper = new \NeuronAI\Providers\AWS\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->stringTool()])]);

        $this->assertSame(
            [['json' => ['result' => 'plain result']]],
            $payload[0]['content'][0]['toolResult']['content']
        );
    }

    public function test_gemini_maps_tool_output_as_response_parts(): void
    {
        $mapper = new \NeuronAI\Providers\Gemini\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->multimodalTool()])]);

        $response = $payload[0]['parts'][0]['functionResponse']['response'];
        $this->assertSame('get_chart', $response['name']);
        $this->assertSame([
            'parts' => [
                ['text' => 'caption'],
                [
                    'inline_data' => [
                        'data' => 'imgdata',
                        'mime_type' => 'image/png',
                    ],
                ],
            ],
        ], $response['content']);
    }

    public function test_gemini_string_result_unchanged(): void
    {
        $mapper = new \NeuronAI\Providers\Gemini\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->stringTool()])]);

        $this->assertSame('plain result', $payload[0]['parts'][0]['functionResponse']['response']['content']);
    }

    public function test_openai_maps_tool_output_as_native_blocks(): void
    {
        $mapper = new \NeuronAI\Providers\OpenAI\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->multimodalTool()])]);

        $this->assertSame([
            ['type' => 'text', 'text' => 'caption'],
            [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,imgdata'],
            ],
        ], $payload[0]['content']);
    }

    public function test_openai_string_result_unchanged(): void
    {
        $mapper = new \NeuronAI\Providers\OpenAI\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->stringTool()])]);

        $this->assertSame('plain result', $payload[0]['content']);
    }

    public function test_openai_responses_maps_tool_output_as_input_blocks(): void
    {
        $mapper = new \NeuronAI\Providers\OpenAI\Responses\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->multimodalTool()])]);

        $this->assertSame('function_call_output', $payload[0]['type']);
        $this->assertSame([
            ['type' => 'input_text', 'text' => 'caption'],
            [
                'type' => 'input_image',
                'image_url' => 'data:image/png;base64,imgdata',
            ],
        ], $payload[0]['output']);
    }

    public function test_openai_responses_string_result_unchanged(): void
    {
        $mapper = new \NeuronAI\Providers\OpenAI\Responses\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->stringTool()])]);

        $this->assertSame('plain result', $payload[0]['output']);
    }

    public function test_mistral_maps_tool_output_as_native_blocks(): void
    {
        $mapper = new \NeuronAI\Providers\Mistral\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->multimodalTool()])]);

        $this->assertSame([
            ['type' => 'text', 'text' => 'caption'],
            [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,imgdata'],
            ],
        ], $payload[0]['content']);
    }

    public function test_mistral_string_result_unchanged(): void
    {
        $mapper = new \NeuronAI\Providers\Mistral\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->stringTool()])]);

        $this->assertSame('plain result', $payload[0]['content']);
    }

    public function test_ollama_falls_back_to_text(): void
    {
        $mapper = new \NeuronAI\Providers\Ollama\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->multimodalTool()])]);

        $this->assertSame('caption', $payload[0]['content']);
    }

    public function test_ollama_string_result_unchanged(): void
    {
        $mapper = new \NeuronAI\Providers\Ollama\MessageMapper();

        $payload = $mapper->map([new ToolResultMessage([$this->stringTool()])]);

        $this->assertSame('plain result', $payload[0]['content']);
    }

    public function test_openai_falls_back_to_text_when_blocks_unmappable(): void
    {
        $mapper = new \NeuronAI\Providers\OpenAI\MessageMapper();

        $tool = ToolCall::make('get_clip', description: 'Render a clip')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([
                new VideoContent('clipdata', SourceType::BASE64, 'video/mp4'),
            ]));

        $payload = $mapper->map([new ToolResultMessage([$tool])]);

        $this->assertSame('', $payload[0]['content']);
    }

    public function test_anthropic_falls_back_to_text_when_blocks_unmappable(): void
    {
        $mapper = new \NeuronAI\Providers\Anthropic\MessageMapper();

        $tool = ToolCall::make('get_clip', description: 'Render a clip')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([
                new VideoContent('clipdata', SourceType::BASE64, 'video/mp4'),
            ]));

        $payload = $mapper->map([new ToolResultMessage([$tool])]);

        $this->assertSame('', $payload[0]['content'][0]['content']);
    }

    public function test_mistral_falls_back_to_text_when_blocks_unmappable(): void
    {
        $mapper = new \NeuronAI\Providers\Mistral\MessageMapper();

        $tool = ToolCall::make('get_clip', description: 'Render a clip')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([
                new VideoContent('clipdata', SourceType::BASE64, 'video/mp4'),
            ]));

        $payload = $mapper->map([new ToolResultMessage([$tool])]);

        $this->assertSame('', $payload[0]['content']);
    }

    public function test_openai_responses_falls_back_to_text_when_blocks_unmappable(): void
    {
        $mapper = new \NeuronAI\Providers\OpenAI\Responses\MessageMapper();

        $tool = ToolCall::make('get_clip', description: 'Render a clip')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([
                new VideoContent('clipdata', SourceType::BASE64, 'video/mp4'),
            ]));

        $payload = $mapper->map([new ToolResultMessage([$tool])]);

        $this->assertSame('', $payload[0]['output']);
    }

    public function test_bedrock_falls_back_to_text_when_blocks_unmappable(): void
    {
        $mapper = new \NeuronAI\Providers\AWS\MessageMapper();

        $tool = ToolCall::make('get_chart', description: 'Render a chart')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([
                new ImageContent('https://example.com/chart.png', SourceType::URL, 'image/png'),
            ]));

        $payload = $mapper->map([new ToolResultMessage([$tool])]);

        $this->assertSame(
            [['json' => ['result' => '']]],
            $payload[0]['content'][0]['toolResult']['content']
        );
    }

    public function test_gemini_falls_back_to_text_when_blocks_unmappable(): void
    {
        $mapper = new \NeuronAI\Providers\Gemini\MessageMapper();

        $tool = ToolCall::make('get_chart', description: 'Render a chart')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([
                new ImageContent('file-id-123', SourceType::ID, 'image/png'),
            ]));

        $payload = $mapper->map([new ToolResultMessage([$tool])]);

        $this->assertSame(
            ['parts' => [['text' => '']]],
            $payload[0]['parts'][0]['functionResponse']['response']['content']
        );
    }
}
