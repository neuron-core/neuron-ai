<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Tavily;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Tavily\TavilyExtractTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function json_encode;

class TavilyExtractFailedUrlTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_a_url_tavily_could_not_extract_is_reported_to_the_model_as_an_error_result(): void
    {
        $tool = new TavilyExtractTool('tavily-key', $this->recordingClient(new Response(200, [], json_encode([
            'results' => [],
            'failed_results' => [['url' => 'https://example.com/missing', 'error' => 'Failed to fetch url']],
        ]))));

        $tool->setInputs(['url' => 'https://example.com/missing'])->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Failed to fetch url', $result->getText());
    }

    public function test_a_malformed_response_body_is_reported_to_the_model_as_an_error_result(): void
    {
        $tool = new TavilyExtractTool('tavily-key', $this->recordingClient(new Response(200, [], '<html>Bad Gateway</html>')));

        $tool->setInputs(['url' => 'https://example.com'])->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
    }
}
