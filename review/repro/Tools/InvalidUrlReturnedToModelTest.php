<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Jina;

use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Jina\JinaUrlReader;
use NeuronAI\Tools\Toolkits\Tavily\TavilyCrawlTool;
use NeuronAI\Tools\Toolkits\Tavily\TavilyExtractTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

class InvalidUrlReturnedToModelTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_an_invalid_url_from_the_model_becomes_an_error_result_not_an_aborted_run(): void
    {
        $tools = [
            new JinaUrlReader('jina-key', $this->recordingClient()),
            new TavilyExtractTool('tavily-key', $this->recordingClient()),
            new TavilyCrawlTool('tavily-key', $this->recordingClient()),
        ];

        foreach ($tools as $tool) {
            $tool->setInputs(['url' => 'example.com/page'])->execute();

            $result = $tool->getResult();
            $this->assertInstanceOf(ToolOutput::class, $result, $tool::class);
            $this->assertTrue($result->isError(), $tool::class);
            $this->assertSame('Invalid URL.', $result->getText(), $tool::class);
        }

        $this->assertSame([], $this->sentRequests);
    }
}
