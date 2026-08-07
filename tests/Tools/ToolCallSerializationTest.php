<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function serialize;
use function unserialize;

/**
 * ADR 0010: a ToolCall is plain data — it serializes natively, with no
 * dehydration/rehydration machinery, because execution capability (the Tool
 * and its dependencies) never travels with it.
 */
class ToolCallSerializationTest extends TestCase
{
    public function test_native_serialization_round_trips_every_field(): void
    {
        $call = ToolCall::make('count_users', 'call_1', ['limit' => 5], 'Count the users in the database');
        $call->setResult('42')
            ->setApprovalReason('Counts rows in production')
            ->setApprovalState(ApprovalState::Rejected, 'not now');

        /** @var ToolCall $copy */
        $copy = unserialize(serialize($call));

        $this->assertSame('count_users', $copy->getName());
        $this->assertSame('Count the users in the database', $copy->getDescription());
        $this->assertSame(['limit' => 5], $copy->getInputs());
        $this->assertSame('call_1', $copy->getCallId());
        $this->assertSame('42', $copy->getResult());
        $this->assertSame(ApprovalState::Rejected, $copy->getApprovalState());
        $this->assertSame('not now', $copy->getRejectReason());
        $this->assertSame('Counts rows in production', $copy->getApprovalReason());
    }

    public function test_multimodal_result_round_trips(): void
    {
        $call = ToolCall::make('chart', 'call_1');
        $call->setResult(new ToolOutput([
            new TextContent('the chart'),
            new ImageContent('base64data', SourceType::BASE64, 'image/png'),
        ]));

        /** @var ToolCall $copy */
        $copy = unserialize(serialize($call));

        $result = $copy->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertCount(2, $result->getBlocks());
        $this->assertSame('the chart', $result->getText());
    }

    public function test_json_wire_shape_matches_the_stored_tool_entry_format(): void
    {
        $call = ToolCall::make('search', 'call_1', ['q' => 'x'], 'Search the web');

        $this->assertSame(
            ['callId', 'name', 'description', 'inputs', 'result', 'approval', 'approvalReason', 'rejectReason'],
            array_keys($call->jsonSerialize())
        );
    }
}
