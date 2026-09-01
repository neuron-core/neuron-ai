<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class MultimodalToolResultHistoryTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/neuron_test_' . uniqid();
        mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testDir);
        }
    }

    public function test_tool_output_result_round_trip(): void
    {
        $key = 'multimodal_result';

        $callTool = ToolCall::make('get_chart', description: 'Render a chart')
            ->setCallId('call_1')
            ->setInputs(['symbol' => 'AAPL']);

        $resultTool = ToolCall::make('get_chart', description: 'Render a chart')
            ->setCallId('call_1')
            ->setInputs(['symbol' => 'AAPL'])
            ->setResult(new ToolOutput([
                new TextContent('Price chart for AAPL'),
                new ImageContent('base64data', SourceType::BASE64, 'image/png'),
            ]));

        $history = new FileChatHistory($this->testDir, $key);
        $history->addMessage(new UserMessage('Show me the AAPL chart'));
        $history->addMessage(new ToolCallMessage(null, [$callTool]));
        $history->addMessage(new ToolResultMessage([$resultTool]));

        // Reload from disk with a fresh instance (simulates a new process).
        $reloaded = new FileChatHistory($this->testDir, $key);
        $messages = $reloaded->getMessages();

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);

        $result = $messages[2]->getToolCalls()[0]->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertCount(2, $result->getBlocks());

        [$text, $image] = $result->getBlocks();
        $this->assertInstanceOf(TextContent::class, $text);
        $this->assertSame('Price chart for AAPL', $text->content);
        $this->assertInstanceOf(ImageContent::class, $image);
        $this->assertSame('base64data', $image->content);
        $this->assertSame(SourceType::BASE64, $image->sourceType);
        $this->assertSame('image/png', $image->mediaType);
    }

    public function test_error_result_round_trip(): void
    {
        $key = 'error_result';

        $callTool = ToolCall::make('send_email', description: 'Send an email')
            ->setCallId('call_1')
            ->setInputs([]);

        $resultTool = ToolCall::make('send_email', description: 'Send an email')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(ToolOutput::error('SMTP connection refused'));

        $history = new FileChatHistory($this->testDir, $key);
        $history->addMessage(new UserMessage('Send the report'));
        $history->addMessage(new ToolCallMessage(null, [$callTool]));
        $history->addMessage(new ToolResultMessage([$resultTool]));

        $reloaded = new FileChatHistory($this->testDir, $key);
        $messages = $reloaded->getMessages();

        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $result = $messages[2]->getToolCalls()[0]->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('SMTP connection refused', $result->getText());
    }

    public function test_string_result_round_trip_unchanged(): void
    {
        $key = 'string_result';

        $callTool = ToolCall::make('get_price', description: 'Get a price')
            ->setCallId('call_1')
            ->setInputs([]);

        $resultTool = ToolCall::make('get_price', description: 'Get a price')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult('42.5');

        $history = new FileChatHistory($this->testDir, $key);
        $history->addMessage(new UserMessage('What is the price?'));
        $history->addMessage(new ToolCallMessage(null, [$callTool]));
        $history->addMessage(new ToolResultMessage([$resultTool]));

        $reloaded = new FileChatHistory($this->testDir, $key);
        $messages = $reloaded->getMessages();

        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $this->assertSame('42.5', $messages[2]->getToolCalls()[0]->getResult());
    }

    public function test_legacy_stored_string_result_deserializes_as_string(): void
    {
        $key = 'legacy_result';
        $filePath = $this->testDir . '/neuron_' . $key . '.chat';

        // Simulate a history stored before multimodal tool output existed.
        file_put_contents($filePath, json_encode([
            [
                'role' => 'user',
                'content' => 'hello',
            ],
            [
                'role' => 'assistant',
                'type' => 'tool_call',
                'content' => null,
                'tools' => [
                    [
                        'callId' => 'call_1',
                        'name' => 'legacy_tool',
                        'description' => 'legacy',
                        'parameters' => [],
                        'inputs' => [],
                        'result' => null,
                    ],
                ],
            ],
            [
                'role' => 'user',
                'type' => 'tool_call_result',
                'content' => null,
                'tools' => [
                    [
                        'callId' => 'call_1',
                        'name' => 'legacy_tool',
                        'description' => 'legacy',
                        'parameters' => [],
                        'inputs' => [],
                        'result' => 'legacy result',
                    ],
                ],
            ],
        ]));

        $history = new FileChatHistory($this->testDir, $key);
        $messages = $history->getMessages();

        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $this->assertSame('legacy result', $messages[2]->getToolCalls()[0]->getResult());
    }

    public function test_empty_tool_output_result_round_trips(): void
    {
        $key = 'empty_multimodal_result';

        $callTool = ToolCall::make('no_op', description: 'Does nothing observable')
            ->setCallId('call_1')
            ->setInputs([]);

        $resultTool = ToolCall::make('no_op', description: 'Does nothing observable')
            ->setCallId('call_1')
            ->setInputs([])
            ->setResult(new ToolOutput([]));

        $history = new FileChatHistory($this->testDir, $key);
        $history->addMessage(new ToolCallMessage(null, [$callTool]));
        $history->addMessage(new ToolResultMessage([$resultTool]));

        $reloaded = new FileChatHistory($this->testDir, $key);
        $messages = $reloaded->getMessages();

        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $result = $messages[1]->getToolCalls()[0]->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertSame([], $result->getBlocks());
        $this->assertSame('', $result->getText());
    }
}
