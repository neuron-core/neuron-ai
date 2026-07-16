<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class ToolOutputTest extends TestCase
{
    public function test_text_factory_stores_text(): void
    {
        $output = ToolOutput::text('hello');

        $this->assertSame('hello', $output->getText());
        $this->assertFalse($output->hasBlocks());
        $this->assertSame([], $output->getBlocks());
    }

    public function test_blocks_factory_stores_blocks(): void
    {
        $blocks = [new TextContent('a'), new TextContent('b')];
        $output = ToolOutput::blocks($blocks);

        $this->assertTrue($output->hasBlocks());
        $this->assertSame($blocks, $output->getBlocks());
        // getText() derives from TextContent blocks when no explicit text
        $this->assertSame('a b', $output->getText());
    }

    public function test_blocks_factory_with_non_text_blocks_has_null_text(): void
    {
        $output = ToolOutput::blocks([
            new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
        ]);

        $this->assertNull($output->getText());
    }

    public function test_get_text_joins_text_blocks_when_no_explicit_text(): void
    {
        $output = ToolOutput::blocks([new TextContent('foo'), new TextContent('bar')]);

        $this->assertSame('foo bar', $output->getText());
    }

    public function test_get_text_skips_non_text_blocks(): void
    {
        $output = ToolOutput::blocks([
            new TextContent('caption'),
            new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
        ]);

        $this->assertSame('caption', $output->getText());
    }

    public function test_get_text_returns_null_when_blocks_have_no_text(): void
    {
        $output = ToolOutput::blocks([
            new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
        ]);

        $this->assertNull($output->getText());
    }

    public function test_explicit_text_takes_precedence_over_blocks(): void
    {
        $output = new ToolOutput(
            text: 'explicit',
            blocks: [new TextContent('from-block')],
        );

        $this->assertSame('explicit', $output->getText());
        $this->assertTrue($output->hasBlocks());
    }

    public function test_json_serialize_shape(): void
    {
        $output = new ToolOutput(
            text: 'fallback',
            blocks: [new TextContent('block-text')],
        );

        $serialized = $output->jsonSerialize();

        $this->assertSame('fallback', $serialized['text']);
        $this->assertCount(1, $serialized['blocks']);
        $this->assertSame('block-text', $serialized['blocks'][0]['content']);
    }

    public function test_set_result_string_normalizes_to_text_output(): void
    {
        $tool = Tool::make('t', 'd');
        $tool->setResult('plain');

        $this->assertSame('plain', $tool->getResult());
        $this->assertFalse($tool->getOutput()->hasBlocks());
        $this->assertSame('plain', $tool->getOutput()->getText());
    }

    public function test_set_result_assoc_array_is_json_encoded_for_bc(): void
    {
        $tool = Tool::make('t', 'd');
        $tool->setResult(['foo' => 'bar']);

        $this->assertSame('{"foo":"bar"}', $tool->getResult());
        $this->assertFalse($tool->getOutput()->hasBlocks());
    }

    public function test_set_result_list_array_is_json_encoded_for_bc(): void
    {
        $tool = Tool::make('t', 'd');
        $tool->setResult(['a', 'b']);

        $this->assertSame('["a","b"]', $tool->getResult());
        $this->assertFalse($tool->getOutput()->hasBlocks());
    }

    public function test_set_result_array_of_content_blocks_is_json_encoded_not_treated_as_blocks(): void
    {
        // Per design: arrays are ALWAYS JSON-encoded. To return blocks, the tool
        // must explicitly return a ToolOutput. This removes any ambiguity.
        $tool = Tool::make('t', 'd');
        $block = new TextContent('block-in-array');
        $tool->setResult([$block]);

        $this->assertFalse($tool->getOutput()->hasBlocks());
        $this->assertNotSame([$block], $tool->getOutput()->getBlocks());
    }

    public function test_set_result_null_yields_empty_text(): void
    {
        $tool = Tool::make('t', 'd');
        $tool->setResult(null);

        $this->assertSame('', $tool->getResult());
        $this->assertSame('', $tool->getOutput()->getText());
    }

    public function test_set_result_scalar_is_cast_to_string(): void
    {
        $tool = Tool::make('t', 'd');
        $tool->setResult(42);

        $this->assertSame('42', $tool->getResult());
    }

    public function test_set_result_tool_output_is_passed_through(): void
    {
        $tool = Tool::make('t', 'd');
        $output = ToolOutput::blocks([
            new TextContent('caption'),
            new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
        ]);
        $tool->setResult($output);

        $this->assertTrue($tool->getOutput()->hasBlocks());
        $this->assertSame($output, $tool->getOutput());
        // getResult falls back to joining TextContent blocks
        $this->assertSame('caption', $tool->getResult());
    }

    public function test_get_result_is_empty_string_before_set_result(): void
    {
        $tool = Tool::make('t', 'd');

        $this->assertSame('', $tool->getResult());
        $this->assertFalse($tool->getOutput()->hasBlocks());
    }

    public function test_json_serialize_emits_string_result_and_result_output(): void
    {
        $tool = Tool::make('t', 'd');
        $tool->setResult(ToolOutput::blocks([new TextContent('block-text')]));

        // Round-trip through JSON so JsonSerializable inner objects get encoded
        $serialized = json_decode(json_encode($tool->jsonSerialize()), true);

        // 'result' is the BC string path
        $this->assertSame('block-text', $serialized['result']);
        // 'resultOutput' is the structured payload
        $this->assertArrayHasKey('resultOutput', $serialized);
        $this->assertIsArray($serialized['resultOutput']);
        $this->assertCount(1, $serialized['resultOutput']['blocks']);
    }

    public function test_json_serialize_result_output_is_null_when_never_set(): void
    {
        $tool = Tool::make('t', 'd');

        $serialized = json_decode(json_encode($tool->jsonSerialize()), true);

        $this->assertSame('', $serialized['result']);
        $this->assertNull($serialized['resultOutput']);
    }

    public function test_callable_returning_string_uses_text_path(): void
    {
        $tool = Tool::make('t', 'd')
            ->setCallable(fn (): string => 'plain-text');
        $tool->execute();

        $this->assertSame('plain-text', $tool->getResult());
        $this->assertFalse($tool->getOutput()->hasBlocks());
    }

    public function test_callable_returning_tool_output_uses_blocks_path(): void
    {
        $blocks = [new TextContent('block-a'), new TextContent('block-b')];

        $tool = Tool::make('t', 'd')
            ->setCallable(fn (): ToolOutput => ToolOutput::blocks($blocks));
        $tool->execute();

        $this->assertTrue($tool->getOutput()->hasBlocks());
        $this->assertSame($blocks, $tool->getOutput()->getBlocks());
        // getResult joins text blocks
        $this->assertSame('block-a block-b', $tool->getResult());
    }
}
