<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function var_export;

/**
 * MCP says a CallToolResult carries typed content and an isError flag; the
 * MCP module promises nothing MCP-specific leaks past ToolInterface.
 */
class McpToolResultContractTest extends TestCase
{
    public function test_an_mcp_error_result_with_an_image_reaches_the_model_as_a_multimodal_error_output(): void
    {
        $mcp = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-11-25']],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [[
                'name' => 'screenshot',
                'description' => 'Capture the page',
                'inputSchema' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']],
            ]]]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['isError' => true, 'content' => [
                ['type' => 'text', 'text' => 'Login wall'],
                ['type' => 'image', 'data' => 'iVBORw0KGgo=', 'mimeType' => 'image/png'],
            ]]],
        );
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('screenshot', 'call_1', ['url' => 'https://example.com'])]),
            new AssistantMessage('The page needs a login.'),
        );

        Agent::make()
            ->setAiProvider($provider)
            ->addTool(McpConnector::make(['transport' => $mcp])->tools())
            ->chat(new UserMessage('Capture example.com'));

        $result = null;
        foreach ($provider->getRecorded()[1]->messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $result = $message->getToolCalls()[0]->getResult();
            }
        }

        $this->assertInstanceOf(ToolOutput::class, $result, 'The MCP content array is JSON-encoded into a string result: ' . var_export($result, true));
        $this->assertTrue($result->isError(), 'isError: true must reach the model as an error result.');
        $blocks = $result->getBlocks();
        $this->assertInstanceOf(TextContent::class, $blocks[0]);
        $this->assertSame('Login wall', $blocks[0]->getContent());
        $this->assertInstanceOf(ImageContent::class, $blocks[1]);
        $this->assertSame('iVBORw0KGgo=', $blocks[1]->getContent());
    }
}
