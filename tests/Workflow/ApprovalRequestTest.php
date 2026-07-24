<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use PHPUnit\Framework\TestCase;

use function json_encode;

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

    public function testFromArrayWithActions(): void
    {
        $data = [
            'message' => 'Test message',
            'actions' => [
                [
                    'id' => 'action1',
                    'name' => 'First Action',
                    'description' => 'Description 1',
                    'decision' => 'pending',
                    'feedback' => null,
                ],
                [
                    'id' => 'action2',
                    'name' => 'Second Action',
                    'description' => 'Description 2',
                    'decision' => 'approved',
                    'feedback' => 'Looks good',
                ],
            ],
        ];

        $request = ApprovalRequest::fromArray($data);

        $this->assertSame('Test message', $request->getMessage());
        $this->assertCount(2, $request->getActions());

        $actions = $request->getActions();
        $this->assertSame('action1', $actions[0]->id);
        $this->assertEquals(ActionDecision::Pending, $actions[0]->decision);

        $this->assertSame('action2', $actions[1]->id);
        $this->assertEquals(ActionDecision::Approved, $actions[1]->decision);
        $this->assertSame('Looks good', $actions[1]->feedback);
    }

    public function testFromArrayWithoutActions(): void
    {
        $request = ApprovalRequest::fromArray(['message' => 'Test message']);

        $this->assertSame('Test message', $request->getMessage());
        $this->assertEmpty($request->getActions());
    }

    public function testJsonSerializeAndFromArrayAreSymmetric(): void
    {
        $original = new ApprovalRequest('Original message', [
            new Action('action1', 'First Action', 'Description 1', ActionDecision::Pending),
            new Action('action2', 'Second Action', 'Description 2', ActionDecision::Approved, 'Great!'),
            new Action('action3', 'Third Action', null, ActionDecision::Rejected, 'Not good'),
        ]);

        $restored = ApprovalRequest::fromArray($original->jsonSerialize());

        $this->assertSame($original->getMessage(), $restored->getMessage());
        $this->assertCount(3, $restored->getActions());

        $restoredActions = $restored->getActions();
        $this->assertEquals(ActionDecision::Pending, $restoredActions[0]->decision);
        $this->assertNull($restoredActions[0]->feedback);

        $this->assertEquals(ActionDecision::Approved, $restoredActions[1]->decision);
        $this->assertSame('Great!', $restoredActions[1]->feedback);

        $this->assertEquals(ActionDecision::Rejected, $restoredActions[2]->decision);
        $this->assertSame('Not good', $restoredActions[2]->feedback);
    }

    public function testGeneratePayloadOmitsPendingActions(): void
    {
        $request = new ApprovalRequest('msg', [
            new Action('pending', 'Pending', null, ActionDecision::Pending),
            new Action('approved', 'Approved', null, ActionDecision::Approved),
        ]);

        $payload = $request->generatePayload();

        $this->assertArrayNotHasKey('pending', $payload);
        $this->assertArrayHasKey('approved', $payload);
    }

    public function testGeneratePayloadMapsApprovedAndBareReject(): void
    {
        $request = new ApprovalRequest('msg', [
            new Action('a', 'A', null, ActionDecision::Approved),
            new Action('r', 'R', null, ActionDecision::Rejected),
        ]);

        $payload = $request->generatePayload();

        $this->assertSame('approve', $payload['a']);
        $this->assertSame('reject', $payload['r']);
    }

    public function testGeneratePayloadMapsRejectWithReason(): void
    {
        $request = new ApprovalRequest('msg', [
            new Action('r', 'R', null, ActionDecision::Rejected, 'Too dangerous'),
        ]);

        $payload = $request->generatePayload();

        $this->assertSame(['reject', 'Too dangerous'], $payload['r']);
    }

    public function testApprovalIsAWaitForEvent(): void
    {
        // A human decision is an external event whose payload is Action[].
        $request = new ApprovalRequest('msg', [new Action('a1', 'Act', 'desc')]);

        $this->assertInstanceOf(\NeuronAI\Workflow\Interrupt\WaitForEventRequest::class, $request);
        $this->assertSame(\NeuronAI\Workflow\Interrupt\InterruptType::WaitForEvent, $request->type());
    }
}
