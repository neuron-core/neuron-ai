<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function json_decode;

class ApprovalRequestTest extends TestCase
{
    public function testConstructorWithMessage(): void
    {
        $request = new ApprovalRequest('Test message');

        $this->assertEquals('Test message', $request->getMessage());
        $this->assertEmpty($request->getActions());
    }

    public function testConstructorWithActions(): void
    {
        $actions = [
            new Action('action1', 'First Action', 'Description 1'),
            new Action('action2', 'Second Action', 'Description 2'),
        ];

        $request = new ApprovalRequest('Test message', $actions);

        $this->assertEquals('Test message', $request->getMessage());
        $this->assertCount(2, $request->getActions());
        $this->assertEquals('action1', $request->getActions()[0]->id);
        $this->assertEquals('action2', $request->getActions()[1]->id);
    }

    public function testConstructorDeduplicatesActionsById(): void
    {
        $request = new ApprovalRequest('Test message', [
            new Action('same', 'First'),
            new Action('same', 'Second'),
        ]);

        $this->assertCount(1, $request->getActions());
    }

    public function testJsonSerializeProducesNestedActionsArray(): void
    {
        $actions = [
            new Action('action1', 'First Action', 'Description 1', ActionDecision::Pending),
            new Action('action2', 'Second Action', 'Description 2', ActionDecision::Approved, 'Looks good'),
        ];

        $serialized = (new ApprovalRequest('Test message', $actions))->jsonSerialize();

        $this->assertSame('Test message', $serialized['message']);
        // actions is a nested array (no double-encoding to a JSON string)
        $this->assertIsArray($serialized['actions']);
        $this->assertCount(2, $serialized['actions']);
        $this->assertSame('action1', $serialized['actions'][0]['id']);
        $this->assertSame('action2', $serialized['actions'][1]['id']);
    }

    public function testJsonEncodeProducesNestedActions(): void
    {
        $request = new ApprovalRequest('Test message', [
            new Action('action1', 'First Action', 'Description 1', ActionDecision::Approved, 'Approved!'),
        ]);

        $decoded = json_decode(json_encode($request), true);

        $this->assertSame('Test message', $decoded['message']);
        $this->assertIsArray($decoded['actions']);
        $this->assertSame('action1', $decoded['actions'][0]['id']);
    }

    public function testActionsAreReadOnlyValueObjects(): void
    {
        // Action is a pure outbound value object: its decision/feedback are set at
        // construction and not mutated afterwards (ADR 0001).
        $approved = new Action('a', 'A', null, ActionDecision::Approved, 'Great!');
        $rejected = new Action('r', 'R', null, ActionDecision::Rejected, 'Nope');

        $this->assertTrue($approved->isApproved());
        $this->assertSame('Great!', $approved->feedback);

        $this->assertTrue($rejected->isRejected());
        $this->assertSame('Nope', $rejected->feedback);
    }

    public function testApprovalIsAWaitForEvent(): void
    {
        // A human decision is an external event whose payload is Action[].
        $request = new ApprovalRequest('msg', [new Action('a1', 'Act', 'desc')]);

        $this->assertInstanceOf(\NeuronAI\Workflow\Interrupt\WaitForEventRequest::class, $request);
        $this->assertSame(\NeuronAI\Workflow\Interrupt\InterruptType::WaitForEvent, $request->type());
    }
}
