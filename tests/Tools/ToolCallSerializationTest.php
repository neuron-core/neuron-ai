<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function json_encode;
use function serialize;
use function unserialize;

/**
 * A ToolCall is plain data — it serializes natively, with no
 * dehydration/rehydration machinery, because execution capability (the Tool
 * and its dependencies) never travels with it.
 */
class ToolCallSerializationTest extends TestCase
{
    public function test_native_serialization_round_trips_every_field(): void
    {
        $call = ToolCall::make('count_users', 'call_1', ['limit' => 5], 'Count the users in the database', deferred: true);
        $call->setResult('42')
            ->setApprovalReason('Counts rows in production')
            ->setApprovalState(ApprovalState::Rejected, 'not now');

        /** @var ToolCall $copy */
        $copy = unserialize(serialize($call));

        $this->assertSame('count_users', $copy->getName());
        $this->assertTrue($copy->isDeferred());
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
            ['callId', 'name', 'description', 'deferred', 'inputs', 'result', 'approval', 'approvalReason', 'rejectReason'],
            array_keys($call->jsonSerialize())
        );
    }

    public function test_json_wire_shape_of_a_settled_rejected_call(): void
    {
        $call = ToolCall::make('search', 'call_1', ['q' => 'x', 'limit' => 2], 'Search the web', deferred: true)
            ->setResult('found')
            ->setApprovalReason('Costs money')
            ->setApprovalState(ApprovalState::Rejected, 'not now');

        $this->assertSame(
            '{"callId":"call_1","name":"search","description":"Search the web","deferred":true,"inputs":{"q":"x","limit":2},"result":"found","approval":"rejected","approvalReason":"Costs money","rejectReason":"not now"}',
            json_encode($call)
        );
    }

    public function test_json_wire_shape_of_a_fresh_call_encodes_empty_inputs_as_an_object(): void
    {
        $this->assertSame(
            '{"callId":null,"name":"now","description":null,"deferred":false,"inputs":{},"result":null,"approval":null,"approvalReason":null,"rejectReason":null}',
            json_encode(ToolCall::make('now'))
        );
    }

    public function test_error_output_keeps_its_flag_on_the_wire_and_through_native_serialization(): void
    {
        $call = ToolCall::make('lookup', 'call_1')->setResult(ToolOutput::error('Rate limited'));

        $this->assertSame(['is_error' => true, 'blocks' => [(new TextContent('Rate limited'))->toArray()]], $call->jsonSerialize()['result']);

        /** @var ToolCall $copy */
        $copy = unserialize(serialize($call));
        $result = $copy->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Rate limited', $result->getText());
    }

    public function test_a_call_without_result_refuses_to_answer_one(): void
    {
        $call = ToolCall::make('pending_tool', 'call_1');

        $this->assertFalse($call->hasResult());
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Tool call pending_tool has no result: it was never executed.');

        $call->getResult();
    }

    #[DataProvider('resultNormalizations')]
    public function test_set_result_normalizes_like_the_tool_does(mixed $value, string $expected): void
    {
        $call = ToolCall::make('x')->setResult($value);

        $this->assertTrue($call->hasResult());
        $this->assertSame($expected, $call->getResult());
    }

    public static function resultNormalizations(): array
    {
        return [
            'string' => ['plain', 'plain'],
            'list' => [[1, 2], '[1,2]'],
            'map' => [['a' => true], '{"a":true}'],
            'empty array' => [[], '[]'],
            'integer' => [42, '42'],
            'void result' => [null, ''],
        ];
    }

    public function test_inputs_and_call_id_can_be_rebound(): void
    {
        $call = ToolCall::make('x', 'call_1', ['a' => 1]);

        $call->setInputs(['b' => 2])->setCallId(null);

        $this->assertSame(['b' => 2], $call->getInputs());
        $this->assertSame(2, $call->getInput('b'));
        $this->assertNull($call->getInput('a'));
        $this->assertNull($call->getCallId());
    }
}
