<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;
use stdClass;

use function unlink;
use function file_exists;
use function file_put_contents;
use function json_encode;

use const DIRECTORY_SEPARATOR;

class ToolOutputHydrationTest extends TestCase
{
    private string $key = 'tool_output_hydration_test';

    protected function tearDown(): void
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'neuron_' . $this->key . '.chat';
        if (file_exists($file)) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function test_block_result_survives_round_trip_through_file_chat_history(): void
    {
        $originalTool = Tool::make('capture', 'snapshots stuff')
            ->setCallId('call_1')
            ->setResult(ToolOutput::blocks([
                new TextContent('caption'),
                new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'),
            ]));

        $persist = new FileChatHistory(__DIR__, $this->key);
        $persist->addMessage(new ToolCallMessage(tools: [$originalTool]));
        $persist->addMessage(new ToolResultMessage([$originalTool]));

        $raw = json_decode((string) file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'neuron_' . $this->key . '.chat'), true);
        $persistedTool = $raw[1]['tools'][0];
        $this->assertArrayNotHasKey('result', $persistedTool, 'result key should be stripped when resultOutput has blocks');
        $this->assertArrayHasKey('resultOutput', $persistedTool);
        $this->assertCount(2, $persistedTool['resultOutput']['blocks']);

        $reload = new FileChatHistory(__DIR__, $this->key);
        $messages = $reload->getMessages();

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);

        $reloadedMessage = $messages[1];
        $this->assertInstanceOf(ToolResultMessage::class, $reloadedMessage);
        $reloadedTool = $reloadedMessage->getTools()[0];
        $this->assertInstanceOf(Tool::class, $reloadedTool);
        $this->assertTrue($reloadedTool->getOutput()->hasBlocks(), 'blocks lost during hydration');

        $blocks = $reloadedTool->getOutput()->getBlocks();
        $this->assertCount(2, $blocks);
        $this->assertInstanceOf(TextContent::class, $blocks[0]);
        $this->assertSame('caption', $blocks[0]->content);
        $this->assertInstanceOf(ImageContent::class, $blocks[1]);
        $this->assertSame('aGVsbG8=', $blocks[1]->content);
        $this->assertSame('image/png', $blocks[1]->mediaType);
        $this->assertSame(SourceType::BASE64, $blocks[1]->sourceType);

        // getResult() still returns text (derived from TextContent blocks)
        $this->assertSame('caption', $reloadedTool->getResult());
    }

    public function test_text_only_result_round_trips_via_bc_string_path(): void
    {
        $originalTool = Tool::make('search', 'searches stuff')
            ->setCallId('call_2')
            ->setResult('plain-result');

        $persist = new FileChatHistory(__DIR__, $this->key);
        $persist->addMessage(new ToolCallMessage(tools: [$originalTool]));
        $persist->addMessage(new ToolResultMessage([$originalTool]));

        $reload = new FileChatHistory(__DIR__, $this->key);
        $messages = $reload->getMessages();

        $reloadedMessage = $messages[1];
        $this->assertInstanceOf(ToolResultMessage::class, $reloadedMessage);
        $reloadedTool = $reloadedMessage->getTools()[0];
        $this->assertInstanceOf(Tool::class, $reloadedTool);
        $this->assertSame('plain-result', $reloadedTool->getResult());
    }

    public function test_legacy_persisted_payload_without_result_output_key_still_loads(): void
    {
        // Simulate a legacy history file written before ToolOutput existed:
        // only 'result' (string) is present, no 'resultOutput'.
        $payload = [
            [
                'type' => 'tool_call',
                'role' => 'assistant',
                'tools' => [
                    [
                        'callId' => 'call_legacy',
                        'name' => 'legacy',
                        'description' => 'legacy tool',
                        'parameters' => [],
                        'inputs' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'tool_call_result',
                'role' => 'user',
                'tools' => [
                    [
                        'callId' => 'call_legacy',
                        'name' => 'legacy',
                        'description' => 'legacy tool',
                        'parameters' => [],
                        'inputs' => new stdClass(),
                        'result' => 'legacy-string-result',
                    ],
                ],
            ],
        ];

        $file = __DIR__ . DIRECTORY_SEPARATOR . 'neuron_' . $this->key . '.chat';
        file_put_contents($file, json_encode($payload));

        $reload = new FileChatHistory(__DIR__, $this->key);
        $messages = $reload->getMessages();

        $reloadedMessage = $messages[1];
        $this->assertInstanceOf(ToolResultMessage::class, $reloadedMessage);
        $reloadedTool = $reloadedMessage->getTools()[0];
        $this->assertInstanceOf(Tool::class, $reloadedTool);
        $this->assertSame('legacy-string-result', $reloadedTool->getResult());
    }
}
