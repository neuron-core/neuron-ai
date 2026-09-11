<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tests\Chat\History\Stub\TestableChatHistory;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class DeferredCallSerializationTest extends TestCase
{
    public function test_history_preserves_execution_type_on_calls_and_results(): void
    {
        $local = new ToolCall('local', 'local');
        $deferred = new ToolCall('browser', 'browser', deferred: true);
        $callMessage = (new ToolCallMessage(null, [$local, $deferred]))->jsonSerialize();
        $local->setResult('local result');
        $deferred->setApprovalState(ApprovalState::Rejected, 'cancelled')->setResult('rejected');
        $resultMessage = (new ToolResultMessage([$local, $deferred]))->jsonSerialize();

        $stored = json_decode(json_encode([$callMessage, $resultMessage]), true);
        $messages = (new TestableChatHistory())->publicDeserialize($stored);
        $this->assertInstanceOf(ToolCallMessage::class, $messages[0]);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertFalse($messages[0]->getToolCalls()[0]->isDeferred());
        $this->assertTrue($messages[0]->getToolCalls()[1]->isDeferred());
        $this->assertFalse($messages[0]->getToolCalls()[1]->hasResult());
        $this->assertFalse($messages[1]->getToolCalls()[0]->isDeferred());
        $this->assertTrue($messages[1]->getToolCalls()[1]->isDeferred());
        $this->assertSame(ApprovalState::Rejected, $messages[1]->getToolCalls()[1]->getApprovalState());
        $this->assertSame('rejected', $messages[1]->getToolCalls()[1]->getResult());
    }
}
