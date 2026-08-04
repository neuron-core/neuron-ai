<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Trajectory;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolDefinition;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

class TrajectoryTest extends TestCase
{
    protected function makeTool(string $name, array $inputs = [], ?string $callId = null): ToolInterface
    {
        return ToolDefinition::make($name, "The {$name} tool")
            ->setInputs($inputs)
            ->setCallId($callId);
    }

    public function testWrapsOriginalMessages(): void
    {
        $messages = [
            new SystemMessage('You are a helpful assistant.'),
            new UserMessage('Hello'),
            new AssistantMessage('Hi! How can I help?'),
            new UserMessage('What is the capital of France?'),
            new AssistantMessage('The capital of France is Paris.'),
        ];

        $trajectory = Trajectory::fromMessages($messages);

        $this->assertSame($messages, $trajectory->messages());
        $this->assertSame(5, $trajectory->count());
        $this->assertSame(['Hello', 'What is the capital of France?'], $trajectory->userMessages());
        $this->assertSame('The capital of France is Paris.', $trajectory->finalAnswer());
    }

    public function testToolRoundTripFoldsToFinalOutcome(): void
    {
        $pending = $this->makeTool('get_weather', ['city' => 'Rome'], 'call_1');
        $executed = $this->makeTool('get_weather', ['city' => 'Rome'], 'call_1')
            ->setResult('Sunny, 24C');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Weather in Rome?'),
            new ToolCallMessage('Let me check.', [$pending]),
            new ToolResultMessage([$executed]),
            new AssistantMessage('It is sunny in Rome.'),
        ]);

        $calls = $trajectory->toolCalls();
        $this->assertCount(1, $calls);
        // The final-outcome entry from the result message wins over the pending snapshot.
        $this->assertSame($executed, $calls[0]);
        $this->assertSame('Sunny, 24C', $calls[0]->getResult());
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame('It is sunny in Rome.', $trajectory->finalAnswer());
    }

    public function testSuspendedTailKeepsPendingEntry(): void
    {
        $gated = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $gated->setApprovalState(ApprovalState::Pending);
        $gated->setApprovalReason('Refunds move money');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund my order'),
            new ToolCallMessage(null, [$gated]),
        ]);

        $call = $trajectory->lastToolCall('refund_order');
        $this->assertInstanceOf(ToolInterface::class, $call);
        $this->assertSame(ApprovalState::Pending, $call->getApprovalState());
        $this->assertSame('Refunds move money', $call->getApprovalReason());
        $this->assertSame('', $trajectory->finalAnswer());
    }

    public function testFinalOutcomeOverridesPendingSnapshot(): void
    {
        // The tool_call message keeps its pending snapshot forever (ADR 0006);
        // the final outcome lives on the ToolResultMessage and must win.
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $rejected = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $rejected->setApprovalState(ApprovalState::Rejected, 'Amount too high');
        $rejected->setResult('rejected by the user');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund my order'),
            new ToolCallMessage(null, [$pending]),
            new ToolResultMessage([$rejected]),
            new AssistantMessage('I cannot process the refund.'),
        ]);

        $call = $trajectory->lastToolCall('refund_order');
        $this->assertInstanceOf(ToolInterface::class, $call);
        $this->assertSame(ApprovalState::Rejected, $call->getApprovalState());
        $this->assertSame('Amount too high', $call->getRejectReason());
        $this->assertSame('rejected by the user', $call->getResult());
    }

    public function testParallelCallsWithDuplicateCallIdsMatchInOrder(): void
    {
        // Gemini uses the tool name as callId — two calls of the same tool share it.
        $first = $this->makeTool('search', ['q' => 'a'], 'search');
        $second = $this->makeTool('search', ['q' => 'b'], 'search');

        $firstDone = $this->makeTool('search', ['q' => 'a'], 'search')->setResult('result A');
        $secondDone = $this->makeTool('search', ['q' => 'b'], 'search')->setResult('result B');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Search a and b'),
            new ToolCallMessage(null, [$first, $second]),
            new ToolResultMessage([$firstDone, $secondDone]),
            new AssistantMessage('Done.'),
        ]);

        $calls = $trajectory->toolCalls('search');
        $this->assertCount(2, $calls);
        $this->assertSame('result A', $calls[0]->getResult());
        $this->assertSame('result B', $calls[1]->getResult());
    }

    public function testToolCallsFilterAndLastToolCall(): void
    {
        $search = $this->makeTool('search', ['q' => 'x'], 'call_1');
        $searchDone = $this->makeTool('search', ['q' => 'x'], 'call_1')->setResult('found');
        $mail = $this->makeTool('send_email', ['to' => 'a@b.c'], 'call_2');
        $mailDone = $this->makeTool('send_email', ['to' => 'a@b.c'], 'call_2')->setResult('sent');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Do things'),
            new ToolCallMessage(null, [$search]),
            new ToolResultMessage([$searchDone]),
            new ToolCallMessage(null, [$mail]),
            new ToolResultMessage([$mailDone]),
            new AssistantMessage('All done.'),
        ]);

        $this->assertCount(2, $trajectory->toolCalls());
        $this->assertCount(1, $trajectory->toolCalls('send_email'));
        $last = $trajectory->lastToolCall();
        $this->assertInstanceOf(ToolInterface::class, $last);
        $this->assertSame('send_email', $last->getName());
        $this->assertNull($trajectory->lastToolCall('unknown_tool'));
    }

    public function testFromChatHistory(): void
    {
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Hello'));
        $history->addMessage(new AssistantMessage('Hi there.'));

        $trajectory = Trajectory::fromChatHistory($history);

        $this->assertCount(2, $trajectory->messages());
        $this->assertSame('Hi there.', $trajectory->finalAnswer());
    }

    public function testUsageAggregation(): void
    {
        $first = new AssistantMessage('Hi!');
        $first->setUsage(new Usage(100, 20));
        $second = new AssistantMessage('Paris.');
        $second->setUsage(new Usage(150, 30));

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Hello'),
            $first,
            new UserMessage('Capital of France?'),
            $second,
        ]);

        $usage = $trajectory->usage();
        $this->assertSame(250, $usage->inputTokens);
        $this->assertSame(50, $usage->outputTokens);
        $this->assertSame(300, $usage->getTotal());
    }

    public function testSurvivesSerializationAcrossForkBoundary(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $rejected = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $rejected->setApprovalState(ApprovalState::Rejected, 'Too expensive');
        $rejected->setResult('rejected by the user');

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund my order'),
            new ToolCallMessage(null, [$pending]),
            new ToolResultMessage([$rejected]),
            new AssistantMessage('I cannot do that.'),
        ]);

        $restored = unserialize(serialize($trajectory));

        $this->assertInstanceOf(Trajectory::class, $restored);
        $this->assertSame($trajectory->count(), $restored->count());
        $this->assertSame('I cannot do that.', $restored->finalAnswer());
        $call = $restored->lastToolCall('refund_order');
        $this->assertInstanceOf(ToolInterface::class, $call);
        $this->assertSame(ApprovalState::Rejected, $call->getApprovalState());
        $this->assertSame('Too expensive', $call->getRejectReason());
    }

    public function testToTranscriptRendering(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $rejected = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $rejected->setApprovalReason('Refunds move money');
        $rejected->setApprovalState(ApprovalState::Rejected, 'Too expensive');
        $rejected->setResult('rejected by the user');

        $trajectory = Trajectory::fromMessages([
            new SystemMessage('You are a refund assistant.'),
            new UserMessage('Refund order 123'),
            new ToolCallMessage('Let me handle that.', [$pending]),
            new ToolResultMessage([$rejected]),
            new AssistantMessage('I cannot process the refund.'),
        ]);

        $transcript = $trajectory->toTranscript();

        $this->assertStringNotContainsString('refund assistant', $transcript);
        $this->assertStringContainsString('User: Refund order 123', $transcript);
        $this->assertStringContainsString('Assistant: Let me handle that.', $transcript);
        $this->assertStringContainsString('Tool call: refund_order({"order_id":"123"})', $transcript);
        $this->assertStringContainsString('(approval requested: Refunds move money)', $transcript);
        $this->assertStringContainsString('[rejected: Too expensive]', $transcript);
        $this->assertStringContainsString('Tool result (refund_order): rejected by the user', $transcript);
        $this->assertStringContainsString('Assistant: I cannot process the refund.', $transcript);
    }

    public function testPendingApprovalRendersInTranscriptWithoutResult(): void
    {
        $pending = $this->makeTool('refund_order', ['order_id' => '123'], 'call_9');
        $pending->setApprovalState(ApprovalState::Pending);

        $trajectory = Trajectory::fromMessages([
            new UserMessage('Refund order 123'),
            new ToolCallMessage(null, [$pending]),
        ]);

        $transcript = $trajectory->toTranscript();

        $this->assertStringContainsString('[pending approval]', $transcript);
        $this->assertStringNotContainsString('Tool result', $transcript);
    }

    public function testAttachmentsRenderInTranscript(): void
    {
        $trajectory = Trajectory::fromMessages([
            new UserMessage([
                new TextContent('Refund this receipt'),
                new ImageContent('https://example.com/receipt.jpg', SourceType::URL, 'image/jpeg'),
                new FileContent('JVBERi0xLjQ=', SourceType::BASE64, 'application/pdf', 'invoice.pdf'),
            ]),
            new AssistantMessage('I see the receipt and the invoice.'),
        ]);

        $transcript = $trajectory->toTranscript();

        $this->assertStringContainsString('User: Refund this receipt', $transcript);
        $this->assertStringContainsString('[attached: image (image/jpeg), file "invoice.pdf" (application/pdf)]', $transcript);
    }
}
