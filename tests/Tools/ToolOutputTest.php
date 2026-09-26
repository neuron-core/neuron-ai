<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class ToolOutputTest extends TestCase
{
    public function test_text_factory(): void
    {
        $output = ToolOutput::text('hello');

        $this->assertCount(1, $output->getBlocks());
        $this->assertInstanceOf(TextContent::class, $output->getBlocks()[0]);
        $this->assertSame('hello', $output->getText());
    }

    public function test_image_factory(): void
    {
        $output = ToolOutput::image('base64data', SourceType::BASE64, 'image/png');

        $block = $output->getBlocks()[0];
        $this->assertInstanceOf(ImageContent::class, $block);
        $this->assertSame('base64data', $block->content);
        $this->assertSame(SourceType::BASE64, $block->sourceType);
        $this->assertSame('image/png', $block->mediaType);
    }

    public function test_file_factory(): void
    {
        $output = ToolOutput::file('base64data', SourceType::BASE64, 'application/pdf', 'report.pdf');

        $block = $output->getBlocks()[0];
        $this->assertInstanceOf(FileContent::class, $block);
        $this->assertSame('base64data', $block->content);
        $this->assertSame('application/pdf', $block->mediaType);
        $this->assertSame('report.pdf', $block->filename);
    }

    public function test_audio_factory(): void
    {
        $output = ToolOutput::audio('https://example.com/audio.mp3', SourceType::URL, 'audio/mpeg');

        $block = $output->getBlocks()[0];
        $this->assertInstanceOf(AudioContent::class, $block);
        $this->assertSame('https://example.com/audio.mp3', $block->content);
        $this->assertSame(SourceType::URL, $block->sourceType);
        $this->assertSame('audio/mpeg', $block->mediaType);
        $this->assertFalse($output->isError());
    }

    public function test_video_factory(): void
    {
        $output = ToolOutput::video('https://example.com/video.mp4', SourceType::URL, 'video/mp4');

        $block = $output->getBlocks()[0];
        $this->assertInstanceOf(VideoContent::class, $block);
        $this->assertSame('https://example.com/video.mp4', $block->content);
        $this->assertSame(SourceType::URL, $block->sourceType);
        $this->assertSame('video/mp4', $block->mediaType);
    }

    public function test_get_text_concatenates_text_blocks_only(): void
    {
        $output = new ToolOutput([
            new TextContent('first'),
            new ImageContent('img', SourceType::BASE64, 'image/png'),
            new TextContent('second'),
        ]);

        $this->assertSame('first second', $output->getText());
    }

    public function test_get_text_is_empty_without_text_blocks(): void
    {
        $output = ToolOutput::image('img', SourceType::BASE64, 'image/png');

        $this->assertSame('', $output->getText());
    }

    public function test_stringable(): void
    {
        $output = ToolOutput::text('hello');

        $this->assertSame('hello', (string) $output);
    }

    public function test_json_serialize_returns_block_arrays(): void
    {
        $output = new ToolOutput([
            new TextContent('caption'),
            new ImageContent('img', SourceType::BASE64, 'image/png'),
        ]);

        $this->assertSame([$output->getBlocks()[0]->toArray(), $output->getBlocks()[1]->toArray()], $output->jsonSerialize());
    }

    public function test_error_factory(): void
    {
        $output = ToolOutput::error('Rate limited, retry after 60s');

        $this->assertTrue($output->isError());
        $this->assertCount(1, $output->getBlocks());
        $this->assertInstanceOf(TextContent::class, $output->getBlocks()[0]);
        $this->assertSame('Rate limited, retry after 60s', $output->getText());
    }

    public function test_plain_outputs_are_not_errors(): void
    {
        $this->assertFalse(ToolOutput::text('ok')->isError());
        $this->assertFalse((new ToolOutput([new TextContent('ok')]))->isError());
    }

    public function test_error_json_serialize_wraps_blocks(): void
    {
        $this->assertSame(
            ['is_error' => true, 'blocks' => [(new TextContent('boom'))->toArray()]],
            ToolOutput::error('boom')->jsonSerialize()
        );
    }

    public function test_tool_set_result_stores_tool_output(): void
    {
        $output = ToolOutput::text('hello');

        $tool = ToolCall::make('test', description: 'test')->setResult($output);

        $this->assertSame($output, $tool->getResult());
    }

    public function test_tool_set_result_keeps_string_behaviour(): void
    {
        $tool = ToolCall::make('test', description: 'test')->setResult('plain');
        $this->assertSame('plain', $tool->getResult());

        $tool = ToolCall::make('test', description: 'test')->setResult(['foo' => 'bar']);
        $this->assertSame('{"foo":"bar"}', $tool->getResult());
    }

    public function test_tool_invoke_returning_tool_output(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'test';
            protected ?string $description = 'Test tool';
            public function __invoke(): ToolOutput
            {
                return new ToolOutput([
                    new TextContent('the chart'),
                    new ImageContent('img', SourceType::BASE64, 'image/png'),
                ]);
            }
        };

        $tool->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertCount(2, $result->getBlocks());
        $this->assertSame('the chart', $result->getText());
    }

    public function test_tool_json_serialize_with_tool_output(): void
    {
        $tool = ToolCall::make('test', description: 'test')
            ->setCallId('call_1')
            ->setResult(new ToolOutput([
                new TextContent('caption'),
                new ImageContent('img', SourceType::BASE64, 'image/png'),
            ]));

        $serialized = $tool->jsonSerialize();

        $this->assertIsArray($serialized['result']);
        $this->assertCount(2, $serialized['result']);
        $this->assertSame('caption', $serialized['result'][0]['content']);
    }

    public function test_tool_json_serialize_with_string_result_unchanged(): void
    {
        $tool = ToolCall::make('test', description: 'test')->setResult('plain');

        $this->assertSame('plain', $tool->jsonSerialize()['result']);
    }

    public function test_error_output_with_several_blocks_projects_all_its_text(): void
    {
        $output = new ToolOutput([
            new TextContent('Upload failed:'),
            new ImageContent('img', SourceType::BASE64, 'image/png'),
            new TextContent('file too large'),
        ], true);

        $this->assertTrue($output->isError());
        $this->assertSame('Upload failed: file too large', (string) $output);
        $this->assertCount(3, $output->jsonSerialize()['blocks']);
    }

    public function test_empty_output_has_no_text_and_no_blocks(): void
    {
        $output = new ToolOutput([]);

        $this->assertSame('', $output->getText());
        $this->assertSame([], $output->jsonSerialize());
    }

    public function test_error_text_is_kept_verbatim(): void
    {
        $feedback = "Line 1\nLine 2 <b>ünïcödé</b> \u{1F600}";

        $this->assertSame($feedback, ToolOutput::error($feedback)->getText());
    }

    public function test_tool_keeps_the_returned_output_instance(): void
    {
        $output = ToolOutput::error('Rate limited');
        $tool = new class ($output) extends Tool {
            protected string $name = 'test';

            public function __construct(protected ToolOutput $output)
            {
            }

            public function __invoke(): ToolOutput
            {
                return $this->output;
            }
        };

        $tool->execute();

        $this->assertSame($output, $tool->getResult());
        $this->assertSame(['is_error' => true, 'blocks' => [(new TextContent('Rate limited'))->toArray()]], $tool->jsonSerialize()['result']);
    }
}
