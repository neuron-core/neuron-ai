<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\Anthropic\MessageMapper as AnthropicMessageMapper;
use NeuronAI\Providers\AWS\MessageMapper as AWSMessageMapper;
use NeuronAI\Providers\Gemini\MessageMapper as GeminiMessageMapper;
use NeuronAI\Providers\Mistral\MessageMapper as MistralMessageMapper;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;
use NeuronAI\Providers\OpenAI\Responses\MessageMapper as OpenAIResponsesMessageMapper;
use NeuronAI\Tools\HasOutput;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class ToolOutputMapperTest extends TestCase
{
    public function test_anthropic_emits_native_blocks_when_output_has_blocks(): void
    {
        $tool = $this->buildBlockTool();

        $payload = (new AnthropicMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $block = $decoded[0]['content'][0];
        $this->assertSame('tool_result', $block['type']);
        $this->assertIsArray($block['content']);
        $this->assertSame('caption', $block['content'][0]['text']);
        $this->assertSame('image', $block['content'][1]['type']);
        $this->assertSame('base64', $block['content'][1]['source']['type']);
        $this->assertSame('aGVsbG8=', $block['content'][1]['source']['data']);
    }

    public function test_anthropic_falls_back_to_string_when_no_blocks(): void
    {
        $tool = $this->buildTextTool();

        $payload = (new AnthropicMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $this->assertSame('plain-result', $decoded[0]['content'][0]['content']);
    }

    public function test_aws_emits_native_blocks_when_output_has_blocks(): void
    {
        $tool = $this->buildBlockTool();

        $payload = (new AWSMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $content = $decoded[0]['content'][0]['toolResult']['content'];
        $this->assertSame('caption', $content[0]['text']);
        $this->assertArrayHasKey('image', $content[1]);
    }

    public function test_aws_falls_back_to_json_wrapper_when_no_blocks(): void
    {
        $tool = $this->buildTextTool();

        $payload = (new AWSMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $content = $decoded[0]['content'][0]['toolResult']['content'];
        $this->assertSame('plain-result', $content[0]['json']['result']);
    }

    public function test_gemini_emits_parts_when_output_has_blocks(): void
    {
        $tool = $this->buildBlockTool();

        $payload = (new GeminiMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $response = $decoded[0]['parts'][0]['functionResponse']['response'];
        $this->assertIsArray($response['content']);
        $this->assertArrayHasKey('parts', $response['content']);
        $this->assertSame('caption', $response['content']['parts'][0]['text']);
        // ImageContent BASE64 maps to inline_data
        $this->assertSame('aGVsbG8=', $response['content']['parts'][1]['inline_data']['data']);
    }

    public function test_gemini_falls_back_to_string_when_no_blocks(): void
    {
        $tool = $this->buildTextTool();

        $payload = (new GeminiMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $response = $decoded[0]['parts'][0]['functionResponse']['response'];
        $this->assertSame('plain-result', $response['content']);
    }

    public function test_openai_emits_content_array_when_output_has_blocks(): void
    {
        $tool = $this->buildBlockTool();

        $payload = (new OpenAIMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $this->assertIsArray($decoded[0]['content']);
        $this->assertSame('text', $decoded[0]['content'][0]['type']);
        $this->assertSame('caption', $decoded[0]['content'][0]['text']);
        $this->assertSame('image_url', $decoded[0]['content'][1]['type']);
    }

    public function test_openai_falls_back_to_string_when_no_blocks(): void
    {
        $tool = $this->buildTextTool();

        $payload = (new OpenAIMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $this->assertSame('plain-result', $decoded[0]['content']);
    }

    public function test_openai_responses_emits_native_blocks_when_output_has_blocks(): void
    {
        $tool = $this->buildBlockTool();

        $payload = (new OpenAIResponsesMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        // First (and only) entry is the function_call_output item
        $this->assertSame('function_call_output', $decoded[0]['type']);
        $this->assertIsArray($decoded[0]['output']);
        $this->assertSame('input_text', $decoded[0]['output'][0]['type']);
        $this->assertSame('caption', $decoded[0]['output'][0]['text']);
        $this->assertSame('input_image', $decoded[0]['output'][1]['type']);
    }

    public function test_openai_responses_falls_back_to_string_when_no_blocks(): void
    {
        $tool = $this->buildTextTool();

        $payload = (new OpenAIResponsesMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $this->assertSame('plain-result', $decoded[0]['output']);
    }

    public function test_mistral_emits_native_blocks_when_output_has_blocks(): void
    {
        $tool = $this->buildBlockTool();

        $payload = (new MistralMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $this->assertIsArray($decoded[0]['content']);
        $this->assertSame('text', $decoded[0]['content'][0]['type']);
        $this->assertSame('caption', $decoded[0]['content'][0]['text']);
        $this->assertSame('image_url', $decoded[0]['content'][1]['type']);
    }

    public function test_mistral_falls_back_to_string_when_no_blocks(): void
    {
        $tool = $this->buildTextTool();

        $payload = (new MistralMessageMapper())->map([new ToolResultMessage([$tool])]);
        $decoded = $this->decode($payload);

        $this->assertSame('plain-result', $decoded[0]['content']);
    }

    public function test_mapper_honors_custom_tool_implementing_has_output(): void
    {
        // A class that implements ToolInterface directly (not extending Tool)
        // but opts into rich output via HasOutput. Mappers must accept it.
        $tool = new class () implements ToolInterface, HasOutput {
            public function getName(): string
            {
                return 'custom';
            }
            public function setName(string $name): ToolInterface
            {
                return $this;
            }
            public function getDescription(): ?string
            {
                return null;
            }
            public function setDescription(?string $description): ToolInterface
            {
                return $this;
            }
            public function addProperty(\NeuronAI\Tools\ToolPropertyInterface $property): ToolInterface
            {
                return $this;
            }
            public function getProperties(): array
            {
                return [];
            }
            public function getRequiredProperties(): array
            {
                return [];
            }
            public function getParameters(): array
            {
                return [];
            }
            public function getInputs(): array
            {
                return [];
            }
            public function getInput(string $key): mixed
            {
                return null;
            }
            public function setInputs(?array $inputs): ToolInterface
            {
                return $this;
            }
            public function getCallId(): string
            {
                return 'call_custom';
            }
            public function setCallId(string $callId): ToolInterface
            {
                return $this;
            }
            public function getResult(): string
            {
                return 'custom-text';
            }
            public function getMaxRuns(): ?int
            {
                return null;
            }
            public function setMaxRuns(int $tries): ToolInterface
            {
                return $this;
            }
            public function visible(bool $visible): ToolInterface
            {
                return $this;
            }
            public function isVisible(): bool
            {
                return true;
            }
            public function setCallable(callable $callback): ToolInterface
            {
                return $this;
            }
            public function execute(): void
            {
            }
            public function jsonSerialize(): array
            {
                return [];
            }
            public function getOutput(): ToolOutput
            {
                return ToolOutput::blocks([new TextContent('from-custom')]);
            }
        };

        foreach ([
            new AnthropicMessageMapper(),
            new AWSMessageMapper(),
            new GeminiMessageMapper(),
            new OpenAIMessageMapper(),
        ] as $mapper) {
            $payload = $mapper->map([new ToolResultMessage([$tool])]);
            $decoded = $this->decode($payload);

            $json = (string) json_encode($decoded);
            $this->assertStringContainsString('from-custom', $json, $mapper::class . ' did not honor HasOutput');
        }
    }

    private function buildBlockTool(): Tool
    {
        $tool = Tool::make('block_tool', 'returns blocks');
        $tool->setCallId('call_1');
        $tool->setResult(ToolOutput::blocks([
            new TextContent('caption'),
            new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
        ]));
        return $tool;
    }

    private function buildTextTool(): Tool
    {
        $tool = Tool::make('text_tool', 'returns text');
        $tool->setCallId('call_1');
        $tool->setResult('plain-result');
        return $tool;
    }

    private function decode(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
